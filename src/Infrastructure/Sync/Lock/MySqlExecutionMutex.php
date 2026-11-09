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

    public function __construct(private readonly DBmysql $db) {}

    public function lock(SyncJobId $syncJobId): void
    {
        $id = $syncJobId->value();
        $result = $this->db->doQuery(sprintf(
            'SELECT `id` FROM `%s` WHERE `id` = %d FOR UPDATE',
            self::JOB_TABLE,
            $id,
        ));

        if ($result === false) {
            throw new \RuntimeException(sprintf(
                'Unable to acquire execution lock for interaction #%d.',
                $id,
            ));
        }
    }
}
