<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * RULE-CRON-001/RULE-10.10.011/012 : cette classe ne contient aucune
 * logique métier, aucun SQL, aucun appel HTTP. Elle adapte
 * uniquement l'appel du planificateur GLPI vers RunActiveSyncJobsService.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron;

use CronTask;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;

/**
 * GLPI CronTask entry point running every active SyncJob.
 *
 * Dependency wiring is delegated to SyncServiceProvider — the same
 * provider front/syncjob.php and front/syncjob.form.php use, so this
 * CronTask, the list screen, and the creation form all build
 * ExecuteSyncJobService/SyncJobRepository identically, in one place.
 */
final class SyncJobCronTask
{
    /**
     * Describes the task in GLPI's CronTask administration screen.
     *
     * @return array<string, string>
     */
   public static function cronInfo(string $name): array {
       return [
           'description' => \__('Executes every active UXConnector synchronization job.', 'uxconnector'),
       ];
   }

    /**
     * GLPI calls this method by convention: `cron` + the name the
     * task was registered under (see Infrastructure\CronRegistry).
     *
     * @return int 1 if at least one job ran (regardless of individual
     *             outcomes — GLPI's convention for "the task did
     *             something"), 0 if there was nothing to run.
     */
   public static function cronSyncJob(CronTask $task): int {
       $result = SyncServiceProvider::runActiveSyncJobsService()->execute();

       $task->setVolume($result->totalJobs);
       $task->addVolume(0); // ensures the volume is flushed even when totalJobs is 0

       $task->log(sprintf(
           '%d job(s) executed: %d succeeded, %d failed.',
           $result->totalJobs,
           $result->succeeded,
           $result->failed
       ));

      foreach ($result->errors as $syncJobId => $errorMessage) {
          $task->log(sprintf('SyncJob #%d: %s', $syncJobId, $errorMessage));
      }

       return $result->hasRun() ? 1 : 0;
   }
}
