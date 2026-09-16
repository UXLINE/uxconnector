<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\InboundBatchProcessorInterface;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\InboundRecordPostProcessorInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ReconciliationContext;
use GlpiPlugin\Uxconnector\Domain\Sync\Writer\SyncItemWriterInterface;

/** Shared inbound record pipeline for polling and webhook interactions. */
final class InboundRecordProcessor
{
    public function __construct(
        private readonly SyncItemWriterInterface $itemWriter,
        private readonly FieldValueTransformer $transformer,
        private readonly JsonPathValueReader $jsonPathReader,
        private readonly ?ObjectLinkRepositoryInterface $objectLinkRepository = null,
        private readonly iterable $postProcessors = [],
        private readonly iterable $batchProcessors = []
    ) {
    }

    /**
     * @param list<mixed> $items
     * @return array{0:int,1:int} processed / failed
     */
    public function process(SyncJob $syncJob, array $items): array
    {
        foreach ($this->batchProcessors as $batchProcessor) {
            if (!$batchProcessor instanceof InboundBatchProcessorInterface) {
                continue;
            }
            if ($batchProcessor->supports($syncJob)) {
                return $batchProcessor->process($syncJob, $items);
            }
        }

        $syncJobId = $syncJob->id();
        if ($syncJobId === null) {
            throw new \RuntimeException('Cannot process records for a SyncJob without a persisted identifier.');
        }

        $processed = 0;
        $failed = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                $failed++;
                continue;
            }

            try {
                $rawExternalId = $this->jsonPathReader->read($item, $syncJob->externalIdField()->value());
                if ($rawExternalId === null || $rawExternalId === '') {
                    $failed++;
                    continue;
                }

                $externalId = new ExternalId((string) $rawExternalId);

                if ($this->mustSkipGlpiAssetRecord($syncJob, $item)) {
                    continue;
                }

                if ($this->mustSkipBeforeFirstZendeskImport($syncJob, $item, $externalId)) {
                    continue;
                }

                $mappedFields = $this->mapItem($item, $syncJob->fieldMappings());
                if ($mappedFields === null) {
                    $failed++;
                    continue;
                }

                $entitiesId = null;
                if ($syncJob->entityAssignmentStrategy() !== null) {
                    $entitiesId = $syncJob->entityAssignmentStrategy()->resolve($item);
                    if ($entitiesId === null) {
                        $failed++;
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
                    $entitiesId
                ), $mappedFields);

                if ($written) {
                    foreach ($this->postProcessors as $postProcessor) {
                        if (!$postProcessor instanceof InboundRecordPostProcessorInterface) {
                            continue;
                        }
                        if ($postProcessor->supports($syncJob)
                            && !$postProcessor->process($syncJob, $item, $externalId)
                        ) {
                            $written = false;
                            break;
                        }
                    }
                }
            } catch (\Throwable) {
                $written = false;
            }

            if ($written) {
                $processed++;
            } else {
                $failed++;
            }
        }

        return [$processed, $failed];
    }



    /**
     * GLPI asset collections can expose templates and soft-deleted records.
     * They are not inventory assets and must never be recreated in the
     * consolidation instance.
     *
     * @param array<string, mixed> $item
     */
    private function mustSkipGlpiAssetRecord(SyncJob $syncJob, array $item): bool
    {
        if (!str_starts_with($syncJob->templateId(), 'glpi-hl.assets.')) {
            return false;
        }

        return ($item['is_deleted'] ?? false) === true
            || (int) ($item['is_deleted'] ?? 0) === 1
            || ($item['is_template'] ?? false) === true
            || (int) ($item['is_template'] ?? 0) === 1;
    }

    /**
     * The non-closed Zendesk template creates new/open/pending/hold/solved
     * tickets and ignores a closed ticket on first encounter. If a ticket was
     * already imported and is later closed in Zendesk, it is still processed so
     * GLPI does not retain a stale open/resolved status at cutover.
     *
     * @param array<string, mixed> $item
     */
    private function mustSkipBeforeFirstZendeskImport(
        SyncJob $syncJob,
        array $item,
        ExternalId $externalId
    ): bool {
        if (!in_array($syncJob->templateId(), [
            'zendesk.tickets.nonclosed.inbound',
            'zendesk.tickets.delta.inbound',
            'zendesk.tickets.active.inbound',
        ], true)) {
            return false;
        }

        $status = strtolower(trim((string) ($this->jsonPathReader->read($item, 'status') ?? '')));
        if (in_array($status, ['new', 'open', 'pending', 'hold', 'solved'], true)) {
            return false;
        }

        if ($this->objectLinkRepository === null) {
            return true;
        }

        return $this->objectLinkRepository->findByExternalId(
            $syncJob->authConfigReference(),
            $syncJob->externalObjectType(),
            $externalId
        ) === null;
    }

    /**
     * @param array<string, mixed> $item
     * @param list<\GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping> $mappings
     *
     * @return array<string, mixed>|null
     */
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
