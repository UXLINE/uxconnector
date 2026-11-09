<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron;

use CronTask;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;

/**
 * Independent supervisor for abandoned UXConnector executions.
 *
 * It intentionally does not execute or resume business work. Its only role is
 * to reconcile stale RUNNING rows to INTERRUPTED/resumable and, when such an
 * abandoned execution is confirmed, release the stale GLPI ExecutionQueue lease.
 */
final class ExecutionWatchdogCronTask
{
    /** @return array<string,string> */
    public static function cronInfo(string $name): array
    {
        return [
            'description' => \__('Detects abandoned UXConnector executions and makes them safely resumable.', 'uxconnector'),
        ];
    }

    public static function cronExecutionWatchdog(CronTask $task): int
    {
        $recovered = SyncServiceProvider::recoverStaleExecutionsService()->execute();
        $queueLeaseRecovered = false;

        if ($recovered > 0) {
            $queueLeaseRecovered = (new ExecutionQueueTaskRecovery())->recoverAfterAbandonedExecution();
        }

        $task->setVolume($recovered);
        $task->log(sprintf(
            '%d abandoned execution(s) marked interrupted%s.',
            $recovered,
            $queueLeaseRecovered ? '; stale ExecutionQueue lease released' : '',
        ));

        return ($recovered > 0 || $queueLeaseRecovered) ? 1 : 0;
    }
}
