<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ExecutionStopRequestRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

final class ExecutionStopRequestRepository implements ExecutionStopRequestRepositoryInterface
{
    private const TABLE = 'glpi_plugin_uxconnector_syncexecutions';

    public function __construct(private readonly DBmysql $db)
    {
    }

    public function requestStop(SyncExecutionId $id): bool
    {
        // Keep the status in the update predicate to prevent a check-then-write race.
        $this->db->update(
            self::TABLE,
            ['stop_requested' => 1],
            ['id' => $id->value(), 'status' => 'running', 'stop_requested' => 0]
        );
        return $this->isStopRequested($id);
    }

    public function clearStopRequest(SyncExecutionId $id): void
    {
        $this->db->update(
            self::TABLE,
            ['stop_requested' => 0],
            ['id' => $id->value(), 'status' => 'pending', 'stop_requested' => 1]
        );
    }

    public function isStopRequested(SyncExecutionId $id): bool
    {
        foreach (
            $this->db->request([
            'SELECT' => ['stop_requested'],
            'FROM' => self::TABLE,
            'WHERE' => ['id' => $id->value()],
            'LIMIT' => 1,
            ]) as $row
        ) {
            return in_array((int) ($row['stop_requested'] ?? 0), [1, self::CANCELLATION_REQUESTED], true);
        }

        return false;
    }
}
