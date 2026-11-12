<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\DTO\RunActiveSyncJobsResult;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;

/** Runs scheduled jobs only. Manual work is handled by RunQueuedExecutionsService. */
final class RunActiveSyncJobsService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $syncJobRepository,
        private readonly SyncJobExecutorInterface $executeSyncJobService,
        private readonly ?AuthConfigRepositoryInterface $authConfigRepository = null,
        private readonly ?SyncExecutionRepositoryInterface $executionRepository = null,
        private readonly ?QueueSyncJobService $queue = null
    ) {
    }

    public function execute(): RunActiveSyncJobsResult
    {
        $succeeded = 0;
        $failed = 0;
        $attempted = 0;
        $errors = [];

        foreach ($this->syncJobRepository->findRunnable() as $syncJob) {
            $id = $syncJob->id();
            if ($id === null) {
                continue;
            }
            if ($this->executionRepository !== null && $this->executionRepository->hasOpenExecution($id)) {
                continue;
            }
            if ($this->authConfigRepository !== null) {
                $connection = $this->authConfigRepository->findById(new AuthConfigId(
                    $syncJob->authConfigReference()->value()
                ));
                if ($connection === null || !$connection->isUsable()) {
                    continue;
                }
            }

            try {
                if (
                    $this->executionRepository !== null && !JobScheduleEvaluator::isDue(
                        $syncJob,
                        $this->executionRepository
                    )
                ) {
                    continue;
                }
            } catch (\Throwable) {
                ++$failed;
                $errors[$id->value()] = 'Invalid per-job scheduling configuration.';
                continue;
            }
            ++$attempted;
            try {
                $execution = $this->queue !== null
                    ? $this->queue->execute($id, 'scheduled')
                    : $this->executeSyncJobService->execute(new ExecuteSyncJobCommand($id));
                if (
                    in_array(
                        $execution->status(),
                        [SyncExecutionStatus::FAILED, SyncExecutionStatus::INTERRUPTED],
                        true
                    )
                ) {
                    ++$failed;
                } else {
                    ++$succeeded;
                }
            } catch (\GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionBusyException) {
                --$attempted;
                continue;
            } catch (\Throwable $exception) {
                ++$failed;
                $errors[$id->value()] = $exception->getMessage();
            }
        }

        return new RunActiveSyncJobsResult($attempted, $succeeded, $failed, $errors);
    }
}
