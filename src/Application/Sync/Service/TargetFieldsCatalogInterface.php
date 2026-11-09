<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

/** Read-only projection of active Fields columns for the interaction editor. */
interface TargetFieldsCatalogInterface
{
    /** @return list<string> */
    public function writableFor(string $itemtype): array;
}
