<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ExecutionStopRequestRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

final class RequestStopSyncExecutionService
{
    public function __construct(
        private readonly SyncExecutionRepositoryInterface $executions,
        private readonly ExecutionStopRequestRepositoryInterface $stopRequests
    ) {
    }

    public function execute(SyncExecutionId $id): void
    {
        $execution = $this->executions->findById($id);
        if ($execution === null) {
            throw new \RuntimeException('Synchronization execution not found.');
        }
        if ($execution->status() !== SyncExecutionStatus::RUNNING) {
            throw new \RuntimeException('Only a running execution can be stopped.');
        }
        if (!$this->stopRequests->requestStop($id)) {
            throw new \RuntimeException('Execution is no longer running; refresh the history.');
        }
    }
}
