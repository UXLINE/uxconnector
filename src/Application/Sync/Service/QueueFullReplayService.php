<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotRunnableException;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncCheckpointRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\ExecutionMutexInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/**
 * Atomically resets a polling checkpoint and queues a complete replay.
 *
 * ObjectLinks are deliberately preserved: replay is a source-navigation
 * concern and must not disable idempotence/reconciliation on the target.
 */
final class QueueFullReplayService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $jobs,
        private readonly SyncExecutionRepositoryInterface $executions,
        private readonly SyncCheckpointRepositoryInterface $checkpoints,
        private readonly TransactionManagerInterface $transaction,
        private readonly ExecutionMutexInterface $mutex
    ) {}

    public function execute(SyncJobId $id): SyncExecution
    {
        $job = $this->jobs->findById($id);
        if ($job === null) {
            throw SyncJobNotFoundException::forId($id);
        }
        if (!$job->isRunnable()) {
            throw SyncJobNotRunnableException::forStatus($job->status());
        }

        $this->transaction->begin();
        try {
            // The same short row mutex as Run now serializes reset + open-run
            // check + enqueue. It is released on commit before any import runs.
            $this->mutex->lock($id);
            if ($this->executions->hasOpenExecution($id)) {
                throw new \RuntimeException('Cannot replay from the beginning while an execution is pending or running.');
            }

            $this->checkpoints->delete($id);
            $execution = $this->executions->save(SyncExecution::queue($id, 'full_replay'));
            $this->transaction->commit();

            return $execution;
        } catch (\Throwable $exception) {
            try {
                $this->transaction->rollback();
            } catch (\Throwable) {
                // A lost DB connection must not replace the original failure.
            }
            throw $exception;
        }
    }
}
