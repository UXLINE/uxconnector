<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ExecutionStopRequestRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\ExecutionMutexInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

final class ResumeSyncExecutionService
{
    public function __construct(
        private readonly SyncExecutionRepositoryInterface $repository,
        private readonly TransactionManagerInterface $transaction,
        private readonly ExecutionMutexInterface $mutex,
        private readonly ?ExecutionStopRequestRepositoryInterface $stopRequests = null
    ) {}
    public function execute(SyncExecutionId $id): SyncExecution
    {
        $execution = $this->repository->findById($id);
        if ($execution === null) {
            throw new \RuntimeException('Synchronization execution not found.');
        }
        $this->transaction->begin();
        try {
            $this->mutex->lock($execution->syncJobId());
            if ($this->repository->hasOpenExecution($execution->syncJobId())) {
                throw new \RuntimeException('Another execution is already pending or running for this interaction.');
            }
            $execution->requeue();
            $execution = $this->repository->save($execution);
            $this->stopRequests?->clearStopRequest($id);
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
