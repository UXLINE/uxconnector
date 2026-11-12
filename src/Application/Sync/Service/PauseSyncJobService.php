<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\PauseSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;

/**
 * Orchestrates the "pause this SyncJob" use case.
 *
 * SyncJob::pause() has no state guard - pausing is always a safe,
 * immediate action, and SyncJobCronTask's findRunnable() will simply
 * stop picking this job up.
 */
final class PauseSyncJobService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $repository,
        private readonly ?JobEntityAccess $entityAccess = null
    ) {
    }

    /**
     * @throws SyncJobNotFoundException if the command's SyncJobId does not
     *         resolve to an existing SyncJob.
     */
    public function execute(PauseSyncJobCommand $command): SyncJob
    {
        $syncJob = $this->repository->findById($command->syncJobId);

        if ($syncJob === null) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::forId(
                $command->syncJobId
            );
        }

        $this->entityAccess?->assertStrategy($syncJob->entityAssignmentStrategy());
        $syncJob->pause();

        return $this->repository->save($syncJob);
    }
}
