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
 * Read-only use case: lists every SyncJob as DTOs.
 *
 * CQRS (adapted) : a pure Query never opens a transaction and never
 * dispatches a Domain Event - same convention as
 * ListAuthConfigsService (Engine bounded context).
 */
final class ListSyncJobsService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $repository
    ) {}

    /**
     * @return list<SyncJobDTO>
     */
    public function execute(): array
    {
        return array_map(
            static fn($syncJob): SyncJobDTO => SyncJobDTO::fromEntity($syncJob),
            $this->repository->findAll(),
        );
    }
}
