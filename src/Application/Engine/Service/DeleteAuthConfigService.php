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

namespace GlpiPlugin\Uxconnector\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Application\Engine\Command\DeleteAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Port\AuthConfigArtifactsCleanerInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigUsageCheckerInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;

/**
 * Orchestrates the permanent deletion of one authentication configuration.
 *
 * Deletion is intentionally refused while at least one SyncJob references the
 * AuthConfig. The repository hard-purges both the configuration and its
 * protected credential inside one transaction.
 */
final class DeleteAuthConfigService
{
    public function __construct(
        private readonly AuthConfigRepositoryInterface $repository,
        private readonly AuthConfigUsageCheckerInterface $usageChecker,
        private readonly TransactionManagerInterface $transaction,
        private readonly ?AuthConfigArtifactsCleanerInterface $artifactsCleaner = null
    ) {}

    public function execute(DeleteAuthConfigCommand $command): void
    {
        if (!$this->repository->exists($command->authConfigId)) {
            throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::forId($command->authConfigId);
        }

        if ($this->usageChecker->isUsed($command->authConfigId)) {
            throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigInUseException::forId($command->authConfigId);
        }

        $this->transaction->begin();

        try {
            $this->artifactsCleaner?->cleanup($command->authConfigId);
            $this->repository->delete($command->authConfigId);
            $this->transaction->commit();
        } catch (\Throwable $exception) {
            $this->transaction->rollback();
            throw $exception;
        }
    }
}
