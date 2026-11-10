<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

final readonly class SemanticEngineConnectionValidator
{
    public const CONNECTOR_ID = 'openai-compatible';

    public function __construct(
        private AuthConfigRepositoryInterface $authConfigs
    ) {
    }

    public function requireCompatible(AuthConfigId $id): AuthConfig
    {
        $connection = $this->authConfigs->findById($id);

        if ($connection === null) {
            throw \GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Exception\SemanticEngineConfigurationException
                ::unknownAuthConfig($id);
        }

        if ($connection->connectorId() !== self::CONNECTOR_ID) {
            throw \GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Exception\SemanticEngineConfigurationException
                ::incompatibleConnector(
                    $id,
                    $connection->connectorId(),
                );
        }

        return $connection;
    }

    public function requireUsable(AuthConfigId $id): AuthConfig
    {
        $connection = $this->requireCompatible($id);

        if (!$connection->isUsable()) {
            throw \GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Exception\SemanticEngineConfigurationException
                ::inactiveAuthConfig(
                    $id,
                    $connection->status()->value,
                );
        }

        return $connection;
    }
}
