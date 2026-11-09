<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Exception;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\ValueObject\SemanticEngineConfigId;

final class SemanticEngineConfigNotFoundException extends \RuntimeException
{
    public static function forId(SemanticEngineConfigId $id): self
    {
        return new self(sprintf(
            'Semantic engine configuration #%d was not found.',
            $id->value(),
        ));
    }
}
