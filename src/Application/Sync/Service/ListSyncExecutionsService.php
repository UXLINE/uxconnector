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

use GlpiPlugin\Uxconnector\Application\Sync\DTO\SyncExecutionDTO;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/**
 * Read-only use case returning a job's execution history as DTOs, most recent first.
 * As a query service, it neither opens a transaction nor dispatches a domain event.
 */
final class ListSyncExecutionsService
{
    public function __construct(
        private readonly SyncExecutionRepositoryInterface $repository
    ) {
    }

    /**
     * @return list<SyncExecutionDTO>
     */
    public function execute(SyncJobId $syncJobId): array
    {
        return array_map(
            static fn ($execution): SyncExecutionDTO => SyncExecutionDTO::fromEntity($execution),
            $this->repository->findBySyncJobId($syncJobId),
        );
    }
}
