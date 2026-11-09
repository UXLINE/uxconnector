<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Enum;

enum SemanticEngineConfigStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
    case ERROR = 'error';

    public function isUsable(): bool
    {
        return $this === self::ACTIVE;
    }
}
