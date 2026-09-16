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

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\DTO\RunActiveSyncJobsResult;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;

/**
 * Executes every currently-runnable (status ACTIVE) SyncJob in turn.
 *
 * This is the use case SyncJobCronTask (Infrastructure) delegates to
 * immediately — RULE-CRON-001/RULE-10.10.011 : a CronTask never
 * contains business logic itself, it only adapts GLPI's scheduler to
 * an Application Service.
 *
 * RULE-CRON-006 (errors individualized) : one SyncJob raising an
 * unexpected exception (i.e. something ExecuteSyncJobService itself
 * could not turn into a FAILED SyncExecution, such as the
 * SyncExecution repository being unavailable) never stops the rest
 * of the batch from running.
 */
final class RunActiveSyncJobsService
{
   public function __construct(
        private readonly SyncJobRepositoryInterface $syncJobRepository,
        private readonly SyncJobExecutorInterface $executeSyncJobService,
        private readonly ?AuthConfigRepositoryInterface $authConfigRepository = null
    ) {
   }

   public function execute(): RunActiveSyncJobsResult {
       $runnableJobs = $this->syncJobRepository->findRunnable();

       $succeeded = 0;
       $failed = 0;
       $attempted = 0;
       $errors = [];

      foreach ($runnableJobs as $syncJob) {
          $id = $syncJob->id();

         if ($id === null) {
            // Cannot happen for a job returned by a Repository,
            // but the Domain type is nullable (a SyncJob can
            // exist in memory before persistence) — guarded
            // defensively rather than asserted away.
            continue;
         }

          // An active interaction attached to a disabled connection is
          // suspended, not failed. It resumes automatically when the
          // connection is activated again. This keeps the connection
          // lifecycle independent from the interaction lifecycle.
         if ($this->authConfigRepository !== null) {
             $connection = $this->authConfigRepository->findById(
                 new AuthConfigId($syncJob->authConfigReference()->value())
             );
            if ($connection === null || !$connection->isUsable()) {
               continue;
            }
         }

          $attempted++;

         try {
             $execution = $this->executeSyncJobService->execute(
                 new ExecuteSyncJobCommand($id)
             );

            if ($execution->status() === SyncExecutionStatus::FAILED) {
               $failed++;
            } else {
                $succeeded++;
            }
         } catch (\Throwable $exception) {
             $failed++;
             $errors[$id->value()] = $exception->getMessage();
         }
      }

       return new RunActiveSyncJobsResult($attempted, $succeeded, $failed, $errors);
   }
}
