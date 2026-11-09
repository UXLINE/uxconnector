<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

/** Queues failed-item replay; actual writes are performed by CronTask. */
final class RetryFailedItemsService
{
    public function __construct(private readonly SyncFailureRepositoryInterface $failures) {}
    public function execute(SyncExecutionId $executionId): int
    {
        return $this->failures->queueByExecution($executionId);
    }
}
