<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\ValueObject;

final readonly class SemanticEngineConfigId
{
    public function __construct(private int $value)
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException('SemanticEngineConfigId must be a positive integer.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }
}
