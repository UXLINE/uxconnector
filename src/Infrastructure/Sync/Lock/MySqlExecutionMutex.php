<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Lock;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\ExecutionMutexInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/** InnoDB row lock used only for the very short queue/resume critical section. */
final class MySqlExecutionMutex implements ExecutionMutexInterface
{
    private const JOB_TABLE = 'glpi_plugin_uxconnector_syncjobs';

    public function __construct(private readonly DBmysql $db)
    {
    }

    public function lock(SyncJobId $syncJobId): void
    {
        $id = $syncJobId->value();
        // InnoDB locks the matching primary-key record until the caller commits or rolls back.
        // Reassigning the same value leaves the job data and modification date intact.
        $this->db->update(self::JOB_TABLE, ['id' => $id], ['id' => $id]);
        $job = $this->db->request([
            'SELECT' => ['id'],
            'FROM' => self::JOB_TABLE,
            'WHERE' => ['id' => $id],
        ]);

        if (count($job) !== 1) {
            throw new \RuntimeException(sprintf(
                'Cannot lock interaction #%d because it no longer exists.',
                $id,
            ));
        }
    }
}
