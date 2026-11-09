<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\DTO\RunActiveSyncJobsResult;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionBusyException;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;

/** Executes only work explicitly queued by users plus queued failed-item retries. */
final class RunQueuedExecutionsService
{
    public function __construct(
        private readonly SyncExecutionRepositoryInterface $executionRepository,
        private readonly SyncJobExecutorInterface $executeSyncJobService,
        private readonly ProcessQueuedFailureRetriesService $failureRetries
    ) {}

    public function execute(bool $processRetries = true): RunActiveSyncJobsResult
    {
        $succeeded = 0;
        $failed = 0;
        $attempted = 0;
        $errors = [];


        try {
            if ($processRetries) {
                $this->failureRetries->execute(50);
            }
        } catch (\Throwable $exception) {
            $errors[0] = 'Failed-item retry queue: ' . $exception->getMessage();
        }

        foreach ($this->executionRepository->findPending(100) as $queued) {
            $executionId = $queued->id();
            if ($executionId === null) {
                continue;
            }

            ++$attempted;
            try {
                $result = $this->executeSyncJobService->execute(
                    new ExecuteSyncJobCommand($queued->syncJobId(), $executionId),
                );
                if (in_array($result->status(), [SyncExecutionStatus::FAILED, SyncExecutionStatus::INTERRUPTED], true)) {
                    ++$failed;
                } else {
                    ++$succeeded;
                }
            } catch (SyncExecutionBusyException) {
                --$attempted;
                continue;
            } catch (\Throwable $exception) {
                ++$failed;
                $errors[$queued->syncJobId()->value()] = $exception->getMessage();
            }
            // One claimed job per pass. Busy head-of-queue jobs do not block
            // unrelated streams, and worker processes retain bounded memory.
            break;
        }

        return new RunActiveSyncJobsResult($attempted, $succeeded, $failed, $errors);
    }
}
