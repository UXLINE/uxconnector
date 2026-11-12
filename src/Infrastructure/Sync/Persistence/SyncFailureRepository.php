<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncFailure;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncErrorCategory;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final class SyncFailureRepository implements SyncFailureRepositoryInterface
{
    private const TABLE = 'glpi_plugin_uxconnector_syncfailures';
    public function __construct(private readonly DBmysql $db)
    {
    }

    public function add(SyncFailure $failure): void
    {
        $ok = $this->db->insert(self::TABLE, [
            'plugin_uxconnector_syncexecutions_id' => $failure->executionId->value(),
            'plugin_uxconnector_syncjobs_id' => $failure->syncJobId->value(),
            'source_type' => $failure->sourceType,
            'source_id' => $failure->sourceId,
            'source_label' => $failure->sourceLabel,
            'target_type' => $failure->targetType,
            'target_id' => $failure->targetId,
            'action' => $failure->action,
            'status' => $failure->status,
            'error_category' => $failure->category->value,
            'error_code' => $failure->errorCode,
            'error_message' => $failure->errorMessage,
            'technical_context' => $failure->technicalContext,
            'source_snapshot' => $failure->sourceSnapshot,
            'attempt' => $failure->attempt,
            'page_number' => $failure->page,
            'cursor_value' => $failure->cursor,
            'retry_status' => $failure->retryStatus,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            throw new \RuntimeException('Unable to persist synchronization failure detail.');
        }
    }

    public function findByExecution(SyncExecutionId $executionId): array
    {
        $rows = $this->db->request([
            'FROM' => self::TABLE,
            'WHERE' => ['plugin_uxconnector_syncexecutions_id' => $executionId->value()],
            'ORDER' => ['id ASC'],
        ]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new SyncFailure(
                $executionId,
                new SyncJobId((int) $row['plugin_uxconnector_syncjobs_id']),
                (string) $row['source_type'],
                $this->nullable($row['source_id'] ?? null),
                $this->nullable($row['source_label'] ?? null),
                $this->nullable($row['target_type'] ?? null),
                isset($row['target_id']) ? (int) $row['target_id'] : null,
                (string) $row['action'],
                (string) $row['status'],
                SyncErrorCategory::tryFrom((string) $row['error_category']) ?? SyncErrorCategory::UNKNOWN,
                $this->nullable($row['error_code'] ?? null),
                (string) $row['error_message'],
                $this->nullable($row['technical_context'] ?? null),
                $this->nullable($row['source_snapshot'] ?? null),
                (int) $row['attempt'],
                (int) $row['page_number'],
                $this->nullable($row['cursor_value'] ?? null),
                (string) $row['retry_status'],
                $this->nullable($row['date_creation'] ?? null),
                (int) $row['id'],
            );
        }
        return $result;
    }

    public function queueByExecution(SyncExecutionId $executionId): int
    {
        $count = 0;
        foreach ($this->findByExecution($executionId) as $failure) {
            if ($failure->id === null || $failure->retryStatus === 'succeeded') {
                continue;
            }
            if ($failure->sourceSnapshot === null && $failure->sourceId === null) {
                $this->updateRetryStatus($failure->id, 'unavailable');
                continue;
            }
            $this->updateRetryStatus($failure->id, 'queued');
            $count++;
        }
        return $count;
    }

    public function findQueued(int $limit = 50): array
    {
        $rows = $this->db->request([
            'FROM' => self::TABLE,
            'WHERE' => ['retry_status' => 'queued'],
            'ORDER' => ['id ASC'],
            'LIMIT' => max(1, min(500, $limit)),
        ]);
        $result = [];
        foreach ($rows as $row) {
            $executionId = new SyncExecutionId((int) $row['plugin_uxconnector_syncexecutions_id']);
            $result[] = new SyncFailure(
                $executionId,
                new SyncJobId((int) $row['plugin_uxconnector_syncjobs_id']),
                (string) $row['source_type'],
                $this->nullable($row['source_id'] ?? null),
                $this->nullable($row['source_label'] ?? null),
                $this->nullable($row['target_type'] ?? null),
                isset($row['target_id']) ? (int) $row['target_id'] : null,
                (string) $row['action'],
                (string) $row['status'],
                SyncErrorCategory::tryFrom((string) $row['error_category']) ?? SyncErrorCategory::UNKNOWN,
                $this->nullable($row['error_code'] ?? null),
                (string) $row['error_message'],
                $this->nullable($row['technical_context'] ?? null),
                $this->nullable($row['source_snapshot'] ?? null),
                (int) $row['attempt'],
                (int) $row['page_number'],
                $this->nullable($row['cursor_value'] ?? null),
                (string) $row['retry_status'],
                $this->nullable($row['date_creation'] ?? null),
                (int) $row['id'],
            );
        }
        return $result;
    }

    public function updateRetryStatus(int $failureId, string $status): void
    {
        if (!$this->db->update(self::TABLE, ['retry_status' => $status], ['id' => $failureId])) {
            throw new \RuntimeException('Unable to update failure retry status.');
        }
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }
}
