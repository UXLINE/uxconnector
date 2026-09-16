<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Transform;

/**
 * Extension point for named field transforms supplied by connector/template
 * packages. The generic mapping engine depends only on this port and never on
 * a vendor-specific implementation.
 */
interface FieldTransformInterface
{
   public function name(): string;

   public function apply(mixed $value): mixed;
}
