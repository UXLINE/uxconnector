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
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron\SyncJobCronTask;

/**
 * Single source of truth for every CronTask UXConnector registers.
 *
 * RULE-10.10.013/014 : registration is centralized here (never
 * scattered across install scripts) and never done by hand in SQL.
 * ADR-10.10.02 : registration happens during install/upgrade, not on
 * every plugin_init() — HookManager::register() never touches
 * CronTask.
 */
final class CronRegistry
{
   private const SYNC_JOB_TASK_NAME = 'SyncJob';

   public static function register(): void {
       self::registerSyncJob();
   }

   public static function unregister(): void {
       // CronTask::unregister() expects the plugin key, not an itemtype/class.
       CronTask::unregister('uxconnector');
   }

   private static function registerSyncJob(): void {
       // CronTask::register() is already idempotent in GLPI: internally it
       // instantiates CronTask and calls getFromDBbyName() before inserting.
       CronTask::register(
           SyncJobCronTask::class,
           self::SYNC_JOB_TASK_NAME,
           HOUR_TIMESTAMP,
           [
               'mode'  => CronTask::MODE_EXTERNAL,
               'state' => CronTask::STATE_WAITING,
           ]
       );
   }
}
