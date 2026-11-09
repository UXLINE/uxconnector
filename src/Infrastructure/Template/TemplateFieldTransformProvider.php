<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Template;

use GlpiPlugin\Uxconnector\Application\Sync\Transform\FieldTransformInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Template\Transform\JiraAdfToTextTransform;

/**
 * Composition point for optional transforms shipped by connector template
 * packages. The generic Sync bounded context consumes only the interface list.
 */
final class TemplateFieldTransformProvider
{
    /** @return list<FieldTransformInterface> */
    public static function extensions(): array
    {
        return [
            new JiraAdfToTextTransform(),
        ];
    }
}
