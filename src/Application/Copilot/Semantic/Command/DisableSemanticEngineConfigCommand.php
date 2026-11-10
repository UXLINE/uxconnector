<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Command;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\ValueObject\SemanticEngineConfigId;

final readonly class DisableSemanticEngineConfigCommand
{
    public function __construct(public SemanticEngineConfigId $id)
    {
    }
}
