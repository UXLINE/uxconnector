<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use DBmysql;
use GlpiPlugin\Uxconnector\Application\Sync\DTO\BatchProcessResult;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\ExecutionAwareBatchProcessorInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncFailure;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncErrorCategory;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

/**
 * Reusable migration orchestration. Fail-fast preflight precedes the first
 * record write. Errors are persisted, never silently transformed to skips.
 */
abstract class AbstractLegacyLocationBatchProcessor implements ExecutionAwareBatchProcessorInterface
{
    protected string $stage = 'source_validation';
    protected ?int $targetId = null;

    public function __construct(
        protected readonly DBmysql $db,
        private readonly AuthConfigRepositoryInterface $authConfigs,
        private readonly LegacyLocationProfileCatalog $catalog,
        protected readonly ObjectLinkRepositoryInterface $objectLinks,
        protected readonly GlpiTargetFieldsWriter $fieldsWriter
    ) {}

    abstract protected function profile(): string;

    /** @param array<string|int,mixed> $row @param array<string,int> $map
     * @return array{0:string,1:int,2:TargetItemtype,3:string}
     */
    abstract protected function processRecord(SyncJob $job, array $row, array $map, string $sourceId): array;

    public function supports(SyncJob $job): bool
    {
        return $job->templateId() === $this->profile();
    }
    public function process(SyncJob $job, array $items): BatchProcessResult
    {
        return $this->processWithContext($job, $items, null, 0, null, null);
    }

    public function processWithContext(
        SyncJob $job,
        array $items,
        ?SyncExecutionId $executionId,
        int $page,
        ?string $cursor,
        ?SyncFailureRepositoryInterface $failureRepository
    ): BatchProcessResult {
        $auth = $this->authConfigs->findById(new AuthConfigId($job->authConfigReference()->value()));
        if ($auth === null) {
            throw new \RuntimeException('GLPI source connection is missing.');
        }
        $map = $this->catalog->resolve($auth, $this->profile());
        $missingGroups = [];
        foreach (LegacyLocationMigrationProfiles::requiredTargetFields($this->profile()) as $type => $labels) {
            try {
                $this->fieldsWriter->assertAvailable($type, $labels);
            } catch (\RuntimeException $error) {
                $missingGroups[] = $error->getMessage();
            }
        }
        if ($missingGroups !== []) {
            throw new \RuntimeException('Target Fields preflight failed: ' . implode(' | ', $missingGroups));
        }
        $this->assertAdditionalPrerequisites();
        $processed = $failed = $created = $updated = $skipped = 0;
        foreach ($items as $row) {
            if (!is_array($row)) {
                $failed++;
                continue;
            }
            $sourceId = '';
            $this->stage = 'source_validation';
            $this->targetId = null;
            try {
                $sourceId = $this->value($row, $map, 'source_id');
                if ($sourceId === '') {
                    throw new \RuntimeException('SOURCE_LOCATION_ID_MISSING');
                }
                [$action, $targetId, $type, $externalType] = \GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime\SyncRuntimeGuard::run(fn(): array => $this->processRecord($job, $row, $map, $sourceId));
                if ($targetId > 0) {
                    $this->rememberLink($job, $sourceId, $type, $targetId, $externalType);
                }
                $processed++;
                if ($action === 'create') {
                    $created++;
                } elseif ($action === 'update') {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $error) {
                $failed++;
                $reason = preg_match('/^[A-Z0-9_]{3,70}$/D', $error->getMessage()) ? $error->getMessage() : 'PROFILE_RECORD_REJECTED';
                error_log('[UXConnector][GLPI profile ' . $this->profile() . '] stage=' . $this->stage . ' reason=' . $reason . ' exception=' . $error::class);
                if ($failureRepository !== null && $executionId !== null && $job->id() !== null) {
                    $failureRepository->add(new SyncFailure(
                        $executionId,
                        $job->id(),
                        $this->profile(),
                        $sourceId,
                        null,
                        null,
                        null,
                        'migration',
                        'error',
                        SyncErrorCategory::POST_PROCESSING,
                        $reason,
                        'GLPI migration stopped at stage ' . $this->stage . '. Verify Fields availability, source data and target permissions.',
                        json_encode(['stage' => $this->stage, 'target_id' => $this->targetId, 'exception' => $error::class], JSON_THROW_ON_ERROR),
                        null,
                        1,
                        $page,
                        $cursor,
                    ));
                }
            }
        }
        return new BatchProcessResult($processed, $failed, $created, $updated, $skipped, 0);
    }

    protected function assertAdditionalPrerequisites(): void {}

    /** @param array<string|int,mixed> $row @param array<string,int> $map */
    protected function value(array $row, array $map, string $logical): string
    {
        $id = $map[$logical] ?? null;
        if ($id === null) {
            return '';
        }
        $raw = $row[$id] ?? $row[(string) $id] ?? null;
        if (is_array($raw)) {
            $raw = $raw['name'] ?? $raw['value'] ?? null;
        }
        // Search APIs must return display labels for Fields dropdowns. A raw
        // integer is an instance-local dropdown option ID, not a GLPI target
        // status, licence price or métier code; fail rather than corrupt it.
        if (is_int($raw) && in_array($logical, [
            'status', 'entity_code', 'amount_ht', 'monthly_amount',
        ], true)) {
            throw new \RuntimeException('SOURCE_DROPDOWN_ID_NOT_RESOLVED');
        }
        if ($logical === 'status' && is_string($raw) && ctype_digit(trim($raw))) {
            throw new \RuntimeException('SOURCE_STATUS_DROPDOWN_ID_NOT_RESOLVED');
        }
        return is_scalar($raw) ? trim(html_entity_decode(strip_tags((string) $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    /** @param array<string,mixed> $where @return array<string,mixed>|null */
    protected function firstUnique(string $table, array $where): ?array
    {
        if (!$this->db->tableExists($table)) {
            throw new \RuntimeException('TARGET_TABLE_UNAVAILABLE');
        }
        $rows = $this->db->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 2]);
        $found = null;
        foreach ($rows as $row) {
            if ($found !== null) {
                throw new \RuntimeException('TARGET_MATCH_AMBIGUOUS');
            }
            $found = $row;
        }
        return $found;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $data @return array<string,mixed> */
    protected function delta(array $existing, array $data): array
    {
        return \GlpiPlugin\Uxconnector\Domain\Sync\Policy\ExistingValuePolicy::additions($existing, $data);
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    protected function nonempty(array $values): array
    {
        return array_filter($values, static fn($v): bool => $v !== null && $v !== '');
    }

    protected function resolveDropdown(string $table, string $className, string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $row = $this->firstUnique($table, ['name' => $name]);
        if ($row !== null) {
            return (int) $row['id'];
        }
        if (!class_exists($className)) {
            throw new \RuntimeException('TARGET_DROPDOWN_CLASS_MISSING');
        }
        $obj = \GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\NativeItemFactory::create($className);
        $id = (int) $obj->add(['name' => $name, 'entities_id' => 0]);
        if ($id <= 0) {
            throw new \RuntimeException('TARGET_DROPDOWN_CREATE_FAILED');
        }
        return $id;
    }

    protected function normalizeDate(string $value): string
    {
        if ($value === '') {
            return '';
        }
        foreach (['!d/m/Y', '!d-m-Y', '!d.m.Y', '!Y-m-d', '!Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['error_count'] === 0 && $errors['warning_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }
        throw new \RuntimeException('SOURCE_DATE_INVALID');
    }

    protected function normalizeMoney(string $value): string
    {
        $value = trim(str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\b(?:EUR|HT|TTC)\b|€/iu', '', $value) ?? '';
        $value = preg_replace('/\s+/u', '', trim($value)) ?? '';
        if (!preg_match('/^\d+(?:[,.]\d{1,2})?$/D', $value)) {
            throw new \RuntimeException('SOURCE_AMOUNT_INVALID_OR_UNRESOLVED_DROPDOWN');
        }
        return str_replace(',', '.', $value);
    }

    protected function rememberLink(SyncJob $job, string $sourceId, TargetItemtype $type, int $itemId, string $externalType): void
    {
        if ($job->id() === null) {
            return;
        }
        $externalId = new ExternalId($sourceId);
        $current = $this->objectLinks->findByExternalId($job->authConfigReference(), $externalType, $externalId);
        if ($current !== null) {
            if ($current->itemId() === $itemId && $current->itemtype() === $type) {
                $current->touch();
                $this->objectLinks->save($current);
                return;
            }
            if ($current->id() !== null) {
                $this->objectLinks->delete($current->id());
            }
        }
        $this->objectLinks->save(ObjectLink::create(
            $job->id(),
            $job->authConfigReference(),
            $externalId,
            $type,
            $itemId,
            $externalType,
        ));
    }
}
