<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Command;

use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

final readonly class CreateSemanticEngineConfigCommand
{
    public function __construct(
        public string $name,
        public AuthConfigId $authConfigId,
        public string $model,
        public string $inferencePath,
        public int $batchSize = 20,
        public float $confidenceThreshold = 0.7,
        public float $temperature = 0.0,
        public string $classifierVersion = '1.0.0'
    ) {}
}
