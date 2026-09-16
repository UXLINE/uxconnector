<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Repository;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\ValueObject\SemanticEngineConfigId;

interface SemanticEngineConfigRepositoryInterface
{
   public function save(SemanticEngineConfig $config): SemanticEngineConfig;
   public function findById(SemanticEngineConfigId $id): ?SemanticEngineConfig;

    /** @return list<SemanticEngineConfig> */
   public function findAll(): array;

   public function findActive(): ?SemanticEngineConfig;
   public function delete(SemanticEngineConfigId $id): void;
}
