<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/** Process ownership without holding an open database transaction during I/O. */
interface SyncWorkLockInterface
{
    public function acquire(SyncJob $job): bool;

    public function assertOwned(): void;

    public function release(): void;
}
