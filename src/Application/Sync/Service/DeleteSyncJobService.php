<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\DeleteSyncJobCommand;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncCheckpointRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;

/** Permanently removes an Interaction and its execution history. */
final class DeleteSyncJobService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $repository,
        private readonly ?SyncExecutionRepositoryInterface $executionRepository = null,
        private readonly ?TransactionManagerInterface $transaction = null,
        private readonly ?SyncCheckpointRepositoryInterface $checkpointRepository = null,
        private readonly ?JobEntityAccess $entityAccess = null
    ) {
    }

    public function execute(DeleteSyncJobCommand $command): void
    {
        $this->entityAccess?->assertJobId($command->syncJobId);
        if (!$this->repository->exists($command->syncJobId)) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::forId(
                $command->syncJobId
            );
        }

        if ($this->executionRepository === null || $this->transaction === null) {
            $this->checkpointRepository?->delete($command->syncJobId);
            $this->repository->delete($command->syncJobId);
            return;
        }

        $this->transaction->begin();
        try {
            foreach ($this->executionRepository->findBySyncJobId($command->syncJobId) as $execution) {
                if ($execution->id() !== null) {
                    $this->executionRepository->delete($execution->id());
                }
            }
            $this->checkpointRepository?->delete($command->syncJobId);
            $this->repository->delete($command->syncJobId);
            $this->transaction->commit();
        } catch (\Throwable $exception) {
            $this->transaction->rollback();
            throw $exception;
        }
    }
}
