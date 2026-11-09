<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncCheckpointRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\ExecutionMutexInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/** Explicit full-replay use case: forgets only the source cursor, never ObjectLinks. */
final class ResetSyncCheckpointService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $jobs,
        private readonly SyncExecutionRepositoryInterface $executions,
        private readonly SyncCheckpointRepositoryInterface $checkpoints,
        private readonly TransactionManagerInterface $transaction,
        private readonly ExecutionMutexInterface $mutex
    ) {}

    public function execute(SyncJobId $id): void
    {
        if ($this->jobs->findById($id) === null) {
            throw SyncJobNotFoundException::forId($id);
        }

        $this->transaction->begin();
        try {
            $this->mutex->lock($id);
            if ($this->executions->hasOpenExecution($id)) {
                throw new \RuntimeException('Cannot reset checkpoint while an execution is pending or running.');
            }
            $this->checkpoints->delete($id);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            try {
                $this->transaction->rollback();
            } catch (\Throwable) {
            }
            throw $e;
        }
    }
}
