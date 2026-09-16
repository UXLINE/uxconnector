<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Command\CreateSemanticEngineConfigCommand;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Repository\SemanticEngineConfigRepositoryInterface;

final readonly class CreateSemanticEngineConfigService
{
   public function __construct(
        private SemanticEngineConfigRepositoryInterface $repository,
        private SemanticEngineConnectionValidator $connectionValidator
    ) {
   }

   public function execute(CreateSemanticEngineConfigCommand $command): SemanticEngineConfig {
       // A draft may reference an inactive connection, but the connection must
       // already exist and be explicitly typed as openai-compatible.
       $this->connectionValidator->requireCompatible($command->authConfigId);

       $config = SemanticEngineConfig::create(
           $command->name,
           $command->authConfigId->value(),
           $command->model,
           $command->inferencePath,
           $command->batchSize,
           $command->confidenceThreshold,
           $command->temperature,
           $command->classifierVersion
       );

       return $this->repository->save($config);
   }
}
