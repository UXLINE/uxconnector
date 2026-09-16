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
 * Read-only use case: lists the execution history of a given SyncJob
 * as DTOs, most recent first.
 *
 * CQRS (adapted) : a pure Query never opens a transaction and never
 * dispatches a Domain Event — same convention as ListSyncJobsService.
 *
 * Écran de consultation de l'historique (§3 du document de synthèse
 * projet) : jusqu'ici, chaque SyncExecution était persistée mais
 * uniquement consultable en base de données — ce Service est le
 * premier maillon applicatif de l'écran qui les rend visibles depuis
 * l'interface GLPI.
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
   public function execute(SyncJobId $syncJobId): array {
       return array_map(
           static fn ($execution): SyncExecutionDTO => SyncExecutionDTO::fromEntity($execution),
           $this->repository->findBySyncJobId($syncJobId)
       );
   }
}
