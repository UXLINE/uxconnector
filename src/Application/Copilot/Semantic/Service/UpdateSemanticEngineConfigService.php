<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Command\UpdateSemanticEngineConfigCommand;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Repository\SemanticEngineConfigRepositoryInterface;

final readonly class UpdateSemanticEngineConfigService
{
    public function __construct(
        private SemanticEngineConfigRepositoryInterface $repository,
        private SemanticEngineConnectionValidator $connectionValidator
    ) {
    }

    public function execute(UpdateSemanticEngineConfigCommand $command): SemanticEngineConfig
    {
        $config = $this->repository->findById($command->id);

        if ($config === null) {
            throw \GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Exception\SemanticEngineConfigNotFoundException
                ::forId($command->id);
        }

        $this->connectionValidator->requireCompatible($command->authConfigId);

        $config->reconfigure(
            $command->name,
            $command->authConfigId->value(),
            $command->model,
            $command->inferencePath,
            $command->batchSize,
            $command->confidenceThreshold,
            $command->temperature,
            $command->classifierVersion,
        );

        return $this->repository->save($config);
    }
}
