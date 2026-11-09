<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ExecutionStopRequestRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

final class ExecutionStopRequestRepository implements ExecutionStopRequestRepositoryInterface
{
    private const TABLE = 'glpi_plugin_uxconnector_syncexecutions';

    public function __construct(private readonly DBmysql $db) {}

    public function requestStop(SyncExecutionId $id): bool
    {
        // SQL conditions are atomic: a completed execution cannot be stopped.
        $ok = $this->db->doQuery(sprintf(
            "UPDATE `%s` SET `stop_requested` = 1 WHERE `id` = %d AND `status` = 'running'",
            self::TABLE,
            $id->value(),
        ));
        if ($ok === false) {
            throw new \RuntimeException('Unable to record stop request; check plugin database migration.');
        }
        return $this->isStopRequested($id);
    }

    public function clearStopRequest(SyncExecutionId $id): void
    {
        if ($this->db->doQuery(sprintf(
            "UPDATE `%s` SET `stop_requested` = 0 WHERE `id` = %d AND `status` = 'pending'",
            self::TABLE,
            $id->value(),
        )) === false) {
            throw new \RuntimeException('Unable to clear stop request when requeuing.');
        }
    }

    public function isStopRequested(SyncExecutionId $id): bool
    {
        $result = $this->db->doQuery(sprintf(
            'SELECT `stop_requested` FROM `%s` WHERE `id` = %d',
            self::TABLE,
            $id->value(),
        ));
        if ($result === false) {
            throw new \RuntimeException('Unable to check stop request; check plugin database migration.');
        }
        $row = $this->db->fetchAssoc($result);
        return is_array($row) && (int) ($row['stop_requested'] ?? 0) === 1;
    }
}
