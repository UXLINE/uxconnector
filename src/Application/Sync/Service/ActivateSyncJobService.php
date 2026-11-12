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

use GlpiPlugin\Uxconnector\Application\Sync\Command\ActivateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;

/**
 * Orchestrates the "activate this SyncJob" use case - the second half
 * of the activation cycle gap identified by two successive external
 * code reviews: a SyncJob created from the UI stayed in DRAFT
 * forever, so SyncJobCronTask (which only reprises `findRunnable()`)
 * never picked it up.
 *
 * Aggregate-level activation rules live in SyncJob::activate() (inbound
 * mappings or outbound HTTP definition, valid source state). The
 * Application layer additionally enforces the cross-context rule that
 * the referenced Connection must exist, have been tested and be active.
 */
final class ActivateSyncJobService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $repository,
        private readonly ?AuthConfigRepositoryInterface $authConfigRepository = null,
        private readonly ?JobEntityAccess $entityAccess = null
    ) {
    }

    /**
     * @throws SyncJobNotFoundException if the command's SyncJobId does not
     *         resolve to an existing SyncJob.
     * @throws InvalidSyncJobStateException if the SyncJob has no
     *         FieldMapping configured, or is not in a status that allows
     *         activation.
     */
    public function execute(ActivateSyncJobCommand $command): SyncJob
    {
        $syncJob = $this->repository->findById($command->syncJobId);

        if ($syncJob === null) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::forId(
                $command->syncJobId
            );
        }

        $this->entityAccess?->assertStrategy($syncJob->entityAssignmentStrategy());

        if ($this->authConfigRepository !== null) {
            $reference = $syncJob->authConfigReference();
            $authConfig = $this->authConfigRepository->findById(new AuthConfigId($reference->value()));
            if ($authConfig === null) {
                throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigUnavailableException
                    ::referenceNotFound($reference);
            }
            if (!$authConfig->isUsable()) {
                throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigUnavailableException
                    ::notUsable($reference);
            }
        }

        $syncJob->activate();

        return $this->repository->save($syncJob);
    }
}
