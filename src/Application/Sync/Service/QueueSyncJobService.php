<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotRunnableException;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\ExecutionMutexInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/** Queues a manual execution and returns immediately to the HTTP caller. */
final class QueueSyncJobService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $jobs,
        private readonly SyncExecutionRepositoryInterface $executions,
        private readonly TransactionManagerInterface $transaction,
        private readonly ExecutionMutexInterface $mutex
    ) {
    }

    public function execute(SyncJobId $id, string $triggerSource = 'manual'): SyncExecution
    {
        $job = $this->jobs->findById($id);
        if ($job === null) {
            throw SyncJobNotFoundException::forId($id);
        }
        if (!$job->isRunnable()) {
            throw SyncJobNotRunnableException::forStatus($job->status());
        }
        if (!in_array($triggerSource, ['manual', 'scheduled'], true)) {
            throw new \InvalidArgumentException('Unsupported synchronization trigger.');
        }
        $execution = SyncExecution::queue($id, $triggerSource);
        $this->transaction->begin();
        try {
            // Serialize the check+insert pair at database level. A simple
            // pre-check is racy when the user double-clicks Run now.
            $this->mutex->lock($id);
            if ($this->executions->hasOpenExecution($id)) {
                throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionBusyException(
                    'An execution is already pending or running for this interaction.'
                );
            }
            if ($triggerSource === 'scheduled') {
                $current = $this->jobs->findById($id);
                if (
                    $current === null || !$current->isRunnable() || !JobScheduleEvaluator::isDue(
                        $current,
                        $this->executions
                    )
                ) {
                    throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionBusyException(
                        'Scheduled job is not due.'
                    );
                }
            }
            $execution = $this->executions->save($execution);
            $this->transaction->commit();
            return $execution;
        } catch (\Throwable $e) {
            try {
                $this->transaction->rollback();
            } catch (\Throwable) {
            }
            throw $e;
        }
    }
}
