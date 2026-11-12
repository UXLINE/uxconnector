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

use GlpiPlugin\Uxconnector\Application\Engine\Command\DisableAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;

/**
 * Orchestrates the "disable this AuthConfig" use case.
 *
 * AuthConfig::disable() has no state guard (reachable from any
 * status) - disabling is always a safe, immediate action, unlike
 * activate() which requires a prior successful test.
 */
final class DisableAuthConfigService
{
    public function __construct(
        private readonly AuthConfigRepositoryInterface $repository
    ) {
    }

    /**
     * @throws AuthConfigNotFoundException if the command's AuthConfigId
     *         does not resolve to an existing AuthConfig.
     */
    public function execute(DisableAuthConfigCommand $command): AuthConfig
    {
        $authConfig = $this->repository->findById($command->authConfigId);

        if ($authConfig === null) {
            throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::forId(
                $command->authConfigId
            );
        }

        $authConfig->disable();

        return $this->repository->save($authConfig);
    }
}
