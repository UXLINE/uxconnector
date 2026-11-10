<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ExecutionStopRequestRepositoryInterface as StopRequest;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final class SyncExecutionPersistenceMapper
{
    /** @param array<string,mixed> $row */
    public function toDomain(array $row): SyncExecution
    {
        foreach (
            ['id','plugin_uxconnector_syncjobs_id','status','started_at','items_processed',
                'items_failed'] as $field
        ) {
            if (!array_key_exists($field, $row)) {
                throw new \RuntimeException(sprintf('Cannot hydrate SyncExecution: missing "%s" field.', $field));
            }
        }
        $status = SyncExecutionStatus::tryFrom((string) $row['status']);
        if ($status === null) {
            throw new \RuntimeException(sprintf(
                'Corrupted SyncExecution row #%s: unknown status.',
                (string) $row['id']
            ));
        }
        return SyncExecution::reconstitute(
            new SyncExecutionId((int) $row['id']),
            new SyncJobId((int) $row['plugin_uxconnector_syncjobs_id']),
            $status,
            new DateTimeImmutable((string) $row['started_at']),
            $this->nullableDate($row['finished_at'] ?? null),
            (int) $row['items_processed'],
            (int) $row['items_failed'],
            $this->nullableString($row['error_message'] ?? null),
            (int) ($row['items_created'] ?? 0),
            (int) ($row['items_updated'] ?? 0),
            (int) ($row['items_skipped'] ?? 0),
            (int) ($row['items_warnings'] ?? 0),
            $this->nullableString($row['phase'] ?? null),
            $this->nullableString($row['checkpoint_cursor'] ?? null),
            (int) ($row['checkpoint_page'] ?? 0),
            $this->nullableString($row['source_id'] ?? null),
            $this->nullableString($row['last_success_source_id'] ?? null),
            $this->nullableDate($row['last_success_at'] ?? null),
            $this->nullableDate($row['heartbeat_at'] ?? null),
            (bool) ($row['resume_available'] ?? false),
            (string) ($row['trigger_source'] ?? 'scheduled'),
            (int) ($row['stop_requested'] ?? 0) === 1,
            isset($row['resume_path']) && (string) $row['resume_path'] !== '' ? (string) $row['resume_path'] : null,
            $status === SyncExecutionStatus::RUNNING
                && (int) ($row['stop_requested'] ?? 0) === StopRequest::CANCELLATION_REQUESTED,
        );
    }

    /** @return array<string,int|string|null> */
    public function toPersistence(SyncExecution $execution): array
    {
        $data = [
            'plugin_uxconnector_syncjobs_id' => $execution->syncJobId()->value(),
            'status' => $execution->status()->value,
            'started_at' => $execution->startedAt()->format('Y-m-d H:i:s'),
            'finished_at' => $execution->finishedAt()?->format('Y-m-d H:i:s'),
            'items_processed' => $execution->itemsProcessed(),
            'items_created' => $execution->itemsCreated(),
            'items_updated' => $execution->itemsUpdated(),
            'items_skipped' => $execution->itemsSkipped(),
            'items_warnings' => $execution->itemsWarnings(),
            'items_failed' => $execution->itemsFailed(),
            'error_message' => $execution->errorMessage(),
            'phase' => $execution->phase(),
            'checkpoint_cursor' => $execution->cursor(),
            'checkpoint_page' => $execution->page(),
            'source_id' => $execution->sourceId(),
            'last_success_source_id' => $execution->lastSuccessfulSourceId(),
            'last_success_at' => $execution->lastSuccessAt()?->format('Y-m-d H:i:s'),
            'heartbeat_at' => $execution->heartbeatAt()?->format('Y-m-d H:i:s'),
            'resume_available' => $execution->resumeAvailable() ? 1 : 0,
            'trigger_source' => $execution->triggerSource(),
            // Do not persist stop_requested here: independent UI requests must not
            // be overwritten by a worker saving an older execution snapshot.
            'resume_path' => $execution->resumePath(),
        ];
        if ($execution->id() !== null) {
            $data['id'] = $execution->id()->value();
        }
        return $data;
    }

    public function assignGeneratedId(SyncExecution $execution, int $generatedId): void
    {
        $execution->assignId(new SyncExecutionId($generatedId));
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        return new DateTimeImmutable((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return (string) $value;
    }
}
