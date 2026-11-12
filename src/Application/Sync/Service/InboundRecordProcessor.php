<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\DTO\BatchProcessResult;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\InboundBatchProcessorInterface;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\ExecutionAwareBatchProcessorInterface;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\InboundRecordPostProcessorInterface;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\PostProcessingDiagnosticInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncFailure;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncErrorCategory;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ReconciliationContext;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\Writer\ActionAwareSyncItemWriterInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Writer\SyncItemWriterInterface;

/** Shared inbound record pipeline for polling and webhook interactions. */
final class InboundRecordProcessor
{
    private readonly SourceSnapshotSanitizer $snapshotSanitizer;

    public function __construct(
        private readonly SyncItemWriterInterface $itemWriter,
        private readonly FieldValueTransformer $transformer,
        private readonly JsonPathValueReader $jsonPathReader,
        private readonly ?ObjectLinkRepositoryInterface $objectLinkRepository = null,
        private readonly iterable $postProcessors = [],
        private readonly iterable $batchProcessors = [],
        private readonly ?SyncFailureRepositoryInterface $failureRepository = null,
        ?SourceSnapshotSanitizer $snapshotSanitizer = null
    ) {
        $this->snapshotSanitizer = $snapshotSanitizer ?? new SourceSnapshotSanitizer();
    }

    /** @param list<mixed> $items @return array{0:int,1:int} */
    public function process(SyncJob $syncJob, array $items): array
    {
        $result = $this->processDetailed($syncJob, $items);
        return [$result->processed, $result->failed];
    }

    /** @param list<mixed> $items */
    public function processDetailed(
        SyncJob $syncJob,
        array $items,
        ?SyncExecutionId $executionId = null,
        int $page = 0,
        ?string $cursor = null,
        ?\Closure $beforeRecord = null
    ): BatchProcessResult {
        foreach ($this->batchProcessors as $batchProcessor) {
            if ($batchProcessor instanceof InboundBatchProcessorInterface && $batchProcessor->supports($syncJob)) {
                if ($batchProcessor instanceof ExecutionAwareBatchProcessorInterface) {
                    return $batchProcessor->processWithContext(
                        $syncJob,
                        $items,
                        $executionId,
                        $page,
                        $cursor,
                        $this->failureRepository
                    );
                }
                return $batchProcessor->process($syncJob, $items);
            }
        }

        $syncJobId = $syncJob->id();
        if ($syncJobId === null) {
            throw new \RuntimeException('Cannot process records for a SyncJob without a persisted identifier.');
        }

        $processed = $failed = $created = $updated = $skipped = $warnings = 0;

        foreach ($items as $item) {
            // Cooperative stop boundary: never interrupt a record mid-write.
            $beforeRecord?->__invoke();
            if (!is_array($item)) {
                $failed++;
                $this->recordFailure(
                    $executionId,
                    $syncJob,
                    null,
                    null,
                    'upsert',
                    SyncErrorCategory::VALIDATION,
                    'INVALID_RECORD',
                    'Source record is not a JSON object.',
                    null,
                    $page,
                    $cursor,
                    false
                );
                continue;
            }

            $rawExternalId = $this->jsonPathReader->read($item, $syncJob->externalIdField()->value());
            $externalIdValue = is_scalar($rawExternalId) ? trim((string) $rawExternalId) : '';
            if ($externalIdValue === '') {
                $failed++;
                $this->recordFailure(
                    $executionId,
                    $syncJob,
                    $item,
                    null,
                    'upsert',
                    SyncErrorCategory::VALIDATION,
                    'MISSING_EXTERNAL_ID',
                    'Required external identifier is missing.',
                    null,
                    $page,
                    $cursor,
                    false
                );
                continue;
            }
            $externalId = new ExternalId($externalIdValue);

            if (
                $this->mustSkipGlpiAssetRecord($syncJob, $item) || $this->mustSkipBeforeFirstZendeskImport(
                    $syncJob,
                    $item,
                    $externalId
                )
            ) {
                $skipped++;
                continue;
            }

            try {
                $missingRequiredField = $this->missingRequiredSourceField($item, $syncJob->fieldMappings());
                $mappedFields = $this->mapItem($item, $syncJob->fieldMappings());
                if ($mappedFields === null) {
                    $failed++;
                    $message = $missingRequiredField !== null
                        ? sprintf('Required source field "%s" is missing.', $missingRequiredField)
                        : 'A required source field is missing.';
                    $this->recordFailure(
                        $executionId,
                        $syncJob,
                        $item,
                        $externalIdValue,
                        'map',
                        SyncErrorCategory::VALIDATION,
                        'REQUIRED_MAPPING_MISSING',
                        $message,
                        null,
                        $page,
                        $cursor,
                        false
                    );
                    continue;
                }

                $entitiesId = null;
                if ($syncJob->entityAssignmentStrategy() !== null) {
                    $entitiesId = $syncJob->entityAssignmentStrategy()->resolve($item);
                    if ($entitiesId === null) {
                        $failed++;
                        $this->recordFailure(
                            $executionId,
                            $syncJob,
                            $item,
                            $externalIdValue,
                            'entity_resolution',
                            SyncErrorCategory::BUSINESS,
                            'ENTITY_UNRESOLVED',
                            'Target GLPI entity could not be resolved.',
                            null,
                            $page,
                            $cursor,
                            false
                        );
                        continue;
                    }
                }

                $written = $this->itemWriter->write(new ReconciliationContext(
                    $syncJobId,
                    $syncJob->authConfigReference(),
                    $syncJob->targetItemtype(),
                    $syncJob->matchField(),
                    $externalId,
                    $syncJob->externalObjectType(),
                    $entitiesId,
                    $syncJob->templateId() === 'zendesk.tickets.delta.inbound'
                        && in_array(
                            $item['status'] ?? null,
                            ['new', 'open', 'pending', 'hold', 'solved', 'closed'],
                            true
                        ),
                ), $mappedFields);

                $action = $this->itemWriter instanceof ActionAwareSyncItemWriterInterface
                    ? $this->itemWriter->lastAction()
                    : 'upsert';

                if (!$written) {
                    $failed++;
                    $this->recordFailure(
                        $executionId,
                        $syncJob,
                        $item,
                        $externalIdValue,
                        $action,
                        SyncErrorCategory::TARGET_WRITE,
                        'TARGET_WRITE_FAILED',
                        'GLPI target write or reconciliation failed.',
                        null,
                        $page,
                        $cursor,
                        false
                    );
                    continue;
                }

                if ($action === 'create') {
                    $created++;
                } elseif (in_array($action, ['update', 'link'], true)) {
                    $updated++;
                } elseif ($action === 'skip') {
                    $skipped++;
                }

                $recordWarnings = 0;
                foreach ($this->postProcessors as $postProcessor) {
                    if (
                        !$postProcessor instanceof InboundRecordPostProcessorInterface || !$postProcessor->supports(
                            $syncJob
                        )
                    ) {
                        continue;
                    }
                    if (!$postProcessor->process($syncJob, $item, $externalId)) {
                        $recordWarnings++;
                        $warnings++;
                        $diagnostic = $postProcessor instanceof PostProcessingDiagnosticInterface ? $postProcessor
                            : null;
                        $this->recordFailure(
                            $executionId,
                            $syncJob,
                            $item,
                            $externalIdValue,
                            'post_process',
                            SyncErrorCategory::POST_PROCESSING,
                            $diagnostic?->lastErrorCode() ?? 'POST_PROCESSING_WARNING',
                            $diagnostic?->lastErrorMessage()
                                ?? 'Target object was written but one or more enrichments/attachments failed.',
                            null,
                            $page,
                            $cursor,
                            true,
                        );
                    }
                }

                $processed++;
            } catch (\Throwable $exception) {
                $failed++;
                $this->recordFailure(
                    $executionId,
                    $syncJob,
                    $item,
                    $externalIdValue,
                    'upsert',
                    SyncErrorCategory::UNKNOWN,
                    $exception::class,
                    $syncJob->targetItemtype() === \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::SIM_CARD
                        ? 'SIM mapping/target write failed; sensitive source values are withheld. Inspect '
                            . 'the exception type and target prerequisites.'
                        : 'Record processing failed; inspect the exception type and target prerequisites. '
                            . 'Source values and database messages are withheld.',
                    sprintf('%s:%d', basename($exception->getFile()), $exception->getLine()),
                    $page,
                    $cursor,
                    false,
                );
            }
        }

        return new BatchProcessResult($processed, $failed, $created, $updated, $skipped, $warnings);
    }

    /** @param array<string,mixed>|null $item */
    private function recordFailure(
        ?SyncExecutionId $executionId,
        SyncJob $syncJob,
        ?array $item,
        ?string $sourceId,
        string $action,
        SyncErrorCategory $category,
        ?string $code,
        string $message,
        ?string $technicalContext,
        int $page,
        ?string $cursor,
        bool $warning
    ): void {
        if ($executionId === null || $this->failureRepository === null || $syncJob->id() === null) {
            return;
        }
        $label = null;
        if ($item !== null) {
            foreach (['subject', 'name', 'title', 'display_name'] as $field) {
                if (isset($item[$field]) && is_scalar($item[$field]) && trim((string) $item[$field]) !== '') {
                    $label = substr(trim((string) $item[$field]), 0, 255);
                    break;
                }
            }
        }
        $this->failureRepository->add(new SyncFailure(
            $executionId,
            $syncJob->id(),
            $syncJob->externalObjectType(),
            $sourceId,
            $label,
            $syncJob->targetItemtype()->value,
            null,
            $action,
            $warning ? 'warning' : 'failed',
            $category,
            $code,
            substr($message, 0, 65535),
            $technicalContext,
            $item !== null
                && $syncJob->targetItemtype() !== \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::SIM_CARD
                ? $this->snapshotSanitizer->encode($item) : null,
            1,
            $page,
            $cursor,
        ));
    }

    /** @param array<string,mixed> $item @param list<\GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping> $mappings */
    private function missingRequiredSourceField(array $item, array $mappings): ?string
    {
        foreach ($mappings as $mapping) {
            if (!$mapping->isRequired()) {
                continue;
            }
            if ($this->jsonPathReader->read($item, $mapping->sourceField()) === null) {
                return $mapping->sourceField();
            }
        }
        return null;
    }

    /** @param array<string,mixed> $item */
    private function mustSkipGlpiAssetRecord(SyncJob $syncJob, array $item): bool
    {
        $templateId = $syncJob->templateId();
        if ($templateId === null || !str_starts_with($templateId, 'glpi-hl.assets.')) {
            return false;
        }
        return ($item['is_deleted'] ?? false) === true
            || (int) ($item['is_deleted'] ?? 0) === 1
            || ($item['is_template'] ?? false) === true
            || (int) ($item['is_template'] ?? 0) === 1;
    }

    /** @param array<string,mixed> $item */
    private function mustSkipBeforeFirstZendeskImport(SyncJob $syncJob, array $item, ExternalId $externalId): bool
    {
        if (
            !in_array($syncJob->templateId(), ['zendesk.tickets.nonclosed.inbound','zendesk.tickets.delta.inbound',
                    'zendesk.tickets.active.inbound'], true)
        ) {
            return false;
        }
        $status = strtolower(trim((string) ($this->jsonPathReader->read($item, 'status') ?? '')));
        if (in_array($status, ['new','open','pending','hold','solved'], true)) {
            return false;
        }
        if ($this->objectLinkRepository === null) {
            return true;
        }
        return $this->objectLinkRepository->findByExternalId(
            $syncJob->authConfigReference(),
            $syncJob->externalObjectType(),
            $externalId,
        ) === null;
    }

    /** @param array<string,mixed> $item @param list<\GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping> $mappings @return array<string,mixed>|null */
    private function mapItem(array $item, array $mappings): ?array
    {
        $mapped = [];
        foreach ($mappings as $mapping) {
            $rawValue = $this->jsonPathReader->read($item, $mapping->sourceField());
            if ($rawValue === null) {
                if ($mapping->isRequired()) {
                    return null;
                }
                continue;
            }
            $mapped[$mapping->targetField()] = $this->transformer->apply($rawValue, $mapping->transform());
        }
        return $mapped;
    }
}
