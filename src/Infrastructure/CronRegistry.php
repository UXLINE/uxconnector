<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure;

use CronTask;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron\ExecutionQueueCronTask;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron\ExecutionWatchdogCronTask;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron\SyncJobCronTask;

/**
 * Single source of truth for every CronTask UXConnector registers.
 *
 * RULE-10.10.013/014 : registration is centralized here (never
 * scattered across install scripts) and never done by hand in SQL.
 * ADR-10.10.02 : registration happens during install/upgrade, not on
 * every plugin_init() - HookManager::register() never touches
 * CronTask.
 */
final class CronRegistry
{
    private const SYNC_JOB_TASK_NAME = 'SyncJob';
    private const EXECUTION_QUEUE_TASK_NAME = 'ExecutionQueue';
    private const EXECUTION_WATCHDOG_TASK_NAME = 'ExecutionWatchdog';

    public static function register(): void
    {
        self::registerSyncJob();
        $scheduler = new CronTask();
        if (
            $scheduler->getFromDBbyName(SyncJobCronTask::class, self::SYNC_JOB_TASK_NAME)
            && (int) ($scheduler->fields['frequency'] ?? 0) !== 60
        ) {
            if (!$scheduler->update(['id' => $scheduler->getID(), 'frequency' => 60])) {
                throw new \RuntimeException('Unable to update UXConnector scheduler frequency.');
            }
        }
        self::registerExecutionQueue();
        self::registerExecutionWatchdog();
    }

    public static function unregister(): void
    {
        // CronTask::unregister() expects the plugin key, not an itemtype/class.
        CronTask::unregister('uxconnector');
    }

    private static function registerSyncJob(): void
    {
        // CronTask::register() is already idempotent in GLPI: internally it
        // instantiates CronTask and calls getFromDBbyName() before inserting.
        CronTask::register(
            SyncJobCronTask::class,
            self::SYNC_JOB_TASK_NAME,
            60,
            [
                'mode'  => CronTask::MODE_EXTERNAL,
                'state' => CronTask::STATE_WAITING,
            ],
        );
    }

    private static function registerExecutionQueue(): void
    {
        CronTask::register(
            ExecutionQueueCronTask::class,
            self::EXECUTION_QUEUE_TASK_NAME,
            60,
            [
                'mode'  => CronTask::MODE_EXTERNAL,
                'state' => CronTask::STATE_WAITING,
            ],
        );
    }

    private static function registerExecutionWatchdog(): void
    {
        CronTask::register(
            ExecutionWatchdogCronTask::class,
            self::EXECUTION_WATCHDOG_TASK_NAME,
            60,
            [
                'mode'  => CronTask::MODE_EXTERNAL,
                'state' => CronTask::STATE_WAITING,
            ],
        );
    }
}
