<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use DateInterval;
use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\SyncWorkLockInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;

/** Converts abandoned RUNNING rows into resumable interruptions after a conservative heartbeat timeout. */
final class RecoverStaleExecutionsService
{
    public function __construct(
        private readonly SyncExecutionRepositoryInterface $repository,
        private readonly int $staleAfterMinutes = 30,
        private readonly ?SyncJobRepositoryInterface $jobs = null,
        private readonly ?SyncWorkLockInterface $lock = null
    ) {
    }

    public function execute(): int
    {
        $threshold = (new DateTimeImmutable())->sub(new DateInterval('PT' . max(1, $this->staleAfterMinutes) . 'M'));
        $recovered = 0;
        foreach ($this->repository->findRunning(100) as $execution) {
            $lastActivity = $execution->heartbeatAt() ?? $execution->startedAt();
            if ($lastActivity >= $threshold) {
                continue;
            }
            $job = $this->jobs?->findById($execution->syncJobId());
            if ($this->lock !== null && ($job === null || !$this->lock->acquire($job))) {
                continue;
            }
            try {
                // Re-read under ownership; the worker may have finished since
                // findRunning() produced its snapshot.
                $current = $execution->id() === null ? null : $this->repository->findById($execution->id());
                if (
                    $current === null || $current->status() !== SyncExecutionStatus::RUNNING
                    || ($current->heartbeatAt() ?? $current->startedAt()) >= $threshold
                ) {
                    continue;
                }
                $current->interrupt(
                    'Worker heartbeat expired; execution can be resumed from its last persisted checkpoint.'
                );
                $this->repository->save($current);
                ++$recovered;
            } finally {
                $this->lock?->release();
            }
        }
        return $recovered;
    }
}
