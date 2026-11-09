<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionBusyException;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\SyncWorkLockInterface;

/** Ownership encloses claim, all writes, and final checkpoint persistence. */
final class LockedSyncJobExecutor implements SyncJobExecutorInterface
{
    public function __construct(
        private readonly SyncJobExecutorInterface $executor,
        private readonly SyncJobRepositoryInterface $jobs,
        private readonly SyncWorkLockInterface $lock
    ) {}

    public function execute(ExecuteSyncJobCommand $command): SyncExecution
    {
        $job = $this->jobs->findById($command->syncJobId);
        if ($job === null) {
            throw SyncJobNotFoundException::forId($command->syncJobId);
        }
        if (!$this->lock->acquire($job)) {
            throw new SyncExecutionBusyException('Another worker owns this job or target stream.');
        }
        try {
            return $this->executor->execute($command);
        } finally {
            $this->lock->release();
        }
    }
}
