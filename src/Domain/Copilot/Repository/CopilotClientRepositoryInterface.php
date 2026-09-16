<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Repository;

interface CopilotClientRepositoryInterface
{
    /** @return list<array<string,mixed>> */
   public function all(): array;

    /** @return array{id:int,key:string} */
   public function create(string $name, string $providerType, int $authConfigReference = 0): array;

   public function setActive(int $id, bool $active): void;

   public function delete(int $id): void;
}
