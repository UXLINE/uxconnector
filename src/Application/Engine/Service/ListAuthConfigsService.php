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

use GlpiPlugin\Uxconnector\Application\Engine\DTO\AuthConfigDTO;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;

/**
 * Read-only use case: lists every AuthConfig as DTOs.
 *
 * CQRS (adapted) : a pure Query never opens a transaction and never
 * dispatches a Domain Event - those only make sense for a Command
 * that changes state (see CreateAuthConfigService).
 */
final class ListAuthConfigsService
{
    public function __construct(
        private readonly AuthConfigRepositoryInterface $repository
    ) {}

    /**
     * @return list<AuthConfigDTO>
     */
    public function execute(): array
    {
        return array_map(
            static fn($authConfig): AuthConfigDTO => AuthConfigDTO::fromEntity($authConfig),
            $this->repository->findAll(),
        );
    }
}
