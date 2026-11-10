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
        private readonly ?SyncExecutionRepositoryInterface $executions = null
    ) {
    }

    public function canRetry(SyncExecutionId $executionId): bool
    {
        $execution = $this->executions?->findById($executionId);
        return $execution?->status() !== SyncExecutionStatus::CANCELLED && !$execution?->cancellationRequested();
    }
    public function execute(SyncExecutionId $executionId): int
    {
        if (!$this->canRetry($executionId)) {
            throw new \RuntimeException('Failed items cannot be retried for a cancelled execution.');
        }
        return $this->failures->queueByExecution($executionId);
    }
}
