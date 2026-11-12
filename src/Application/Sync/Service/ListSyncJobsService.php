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

use GlpiPlugin\Uxconnector\Application\Sync\DTO\SyncJobDTO;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;

/**
 * Read-only use case: lists jobs accessible in the user's entity scope.
 *
 * CQRS (adapted) : a pure Query never opens a transaction and never
 * dispatches a Domain Event - same convention as
 * ListAuthConfigsService (Engine bounded context).
 */
final class ListSyncJobsService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $repository,
        private readonly JobEntityAccess $entityAccess
    ) {
    }

    /**
     * @return list<SyncJobDTO>
     */
    public function execute(): array
    {
        $jobs = [];
        foreach ($this->repository->findAll() as $job) {
            if ($this->entityAccess->canAccessStrategy($job->entityAssignmentStrategy())) {
                $jobs[] = SyncJobDTO::fromEntity($job);
            }
        }
        return $jobs;
    }
}
