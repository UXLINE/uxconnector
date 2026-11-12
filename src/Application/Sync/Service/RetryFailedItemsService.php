<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

/** Queues failed-item replay; actual writes are performed by CronTask. */
final class RetryFailedItemsService
{
    public function __construct(
        private readonly SyncFailureRepositoryInterface $failures,
        private readonly ?SyncExecutionRepositoryInterface $executions = null,
        private readonly ?JobEntityAccess $entityAccess = null
    ) {
    }

    public function canRetry(SyncExecutionId $executionId): bool
    {
        $execution = $this->executions?->findById($executionId);
        return $execution?->status() !== SyncExecutionStatus::CANCELLED && !$execution?->cancellationRequested();
    }
    public function execute(SyncExecutionId $executionId): int
    {
        if ($this->entityAccess !== null) {
            $execution = $this->executions?->findById($executionId);
            if ($execution === null) {
                throw new \RuntimeException('Synchronization execution not found.');
            }
            $this->entityAccess->assertJobId($execution->syncJobId());
        }
        if (!$this->canRetry($executionId)) {
            throw new \RuntimeException('Failed items cannot be retried for a cancelled execution.');
        }
        return $this->failures->queueByExecution($executionId);
    }
}
