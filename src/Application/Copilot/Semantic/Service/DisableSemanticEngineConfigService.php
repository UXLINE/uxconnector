<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Command\DisableSemanticEngineConfigCommand;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Repository\SemanticEngineConfigRepositoryInterface;

final readonly class DisableSemanticEngineConfigService
{
    public function __construct(
        private SemanticEngineConfigRepositoryInterface $repository
    ) {}

    public function execute(DisableSemanticEngineConfigCommand $command): SemanticEngineConfig
    {
        $config = $this->repository->findById($command->id);

        if ($config === null) {
            throw \GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Exception\SemanticEngineConfigNotFoundException::forId($command->id);
        }

        if (!$config->isUsable() && $config->status()->value === 'disabled') {
            return $config;
        }

        $config->disable();

        return $this->repository->save($config);
    }
}
