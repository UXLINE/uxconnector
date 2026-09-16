<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Command\ActivateSemanticEngineConfigCommand;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Repository\SemanticEngineConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

final readonly class ActivateSemanticEngineConfigService
{
   public function __construct(
        private SemanticEngineConfigRepositoryInterface $repository,
        private SemanticEngineConnectionValidator $connectionValidator
    ) {
   }

   public function execute(ActivateSemanticEngineConfigCommand $command): SemanticEngineConfig {
       $config = $this->repository->findById($command->id);

      if ($config === null) {
          throw \GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Exception\SemanticEngineConfigNotFoundException::forId($command->id);
      }

      if ($config->isUsable()) {
          return $config;
      }

       $this->connectionValidator->requireUsable(
           new AuthConfigId($config->authConfigReference())
       );

       $active = $this->repository->findActive();

      if ($active !== null
           && $active->id() !== null
           && $active->id()->value() !== $command->id->value()
       ) {
          throw \GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Exception\SemanticEngineConfigurationException::anotherConfigAlreadyActive(
              $active->id()
          );
      }

       $config->activate();

       return $this->repository->save($config);
   }
}
