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

use GlpiPlugin\Uxconnector\Application\Engine\Command\ActivateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Exception\InvalidAuthConfigStateException;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;

/**
 * Orchestrates the "activate this AuthConfig" use case.
 *
 * All the business rule lives in AuthConfig::activate() itself (only
 * reachable from TESTING) - this Service only loads the aggregate,
 * asks it to activate, and persists the result.
 */
final class ActivateAuthConfigService
{
    public function __construct(
        private readonly AuthConfigRepositoryInterface $repository
    ) {}

    /**
     * @throws AuthConfigNotFoundException if the command's AuthConfigId
     *         does not resolve to an existing AuthConfig.
     * @throws InvalidAuthConfigStateException if the AuthConfig is not
     *         currently in TESTING status.
     */
    public function execute(ActivateAuthConfigCommand $command): AuthConfig
    {
        $authConfig = $this->repository->findById($command->authConfigId);

        if ($authConfig === null) {
            throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::forId($command->authConfigId);
        }

        $authConfig->activate();

        return $this->repository->save($authConfig);
    }
}
