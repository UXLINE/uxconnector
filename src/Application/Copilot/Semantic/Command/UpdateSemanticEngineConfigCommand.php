<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Command;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\ValueObject\SemanticEngineConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

final readonly class UpdateSemanticEngineConfigCommand
{
   public function __construct(
        public SemanticEngineConfigId $id,
        public string $name,
        public AuthConfigId $authConfigId,
        public string $model,
        public string $inferencePath,
        public int $batchSize,
        public float $confidenceThreshold,
        public float $temperature,
        public string $classifierVersion
    ) {
   }
}
