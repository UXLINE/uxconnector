<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Repository\CopilotClientRepositoryInterface;

final readonly class CreateCopilotClientService
{
   public function __construct(private CopilotClientRepositoryInterface $repository) {}

   /**
    * @return array{id: int, key: string}
    */
   public function execute(string $name, string $providerType, int $authConfigReference = 0): array {
       return $this->repository->create($name, $providerType, $authConfigReference);
   }
}
