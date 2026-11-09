<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Writer;

interface ActionAwareSyncItemWriterInterface extends SyncItemWriterInterface
{
    /** create|update|link|none */
    public function lastAction(): string;
}
