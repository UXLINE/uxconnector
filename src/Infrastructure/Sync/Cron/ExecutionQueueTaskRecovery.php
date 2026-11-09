<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron;

use CronTask;

/**
 * Repairs only the technical GLPI lease left by an abruptly terminated
 * ExecutionQueue process. It never enables an administratively disabled task.
 */
final class ExecutionQueueTaskRecovery
{
    public function recoverAfterAbandonedExecution(): bool
    {
        $cron = new CronTask();
        if (!$cron->getFromDBbyName(ExecutionQueueCronTask::class, 'ExecutionQueue')) {
            return false;
        }

        if ((int) ($cron->fields['state'] ?? -1) !== CronTask::STATE_RUNNING) {
            return false;
        }

        // This adapter is called only after the Application watchdog has
        // positively recovered one or more stale RUNNING executions. Therefore
        // we do not reset a healthy long-running queue lease by elapsed time alone.
        // Use GLPI's public CronTask state-reset API rather than ad-hoc SQL.
        return $cron->resetState();
    }
}
