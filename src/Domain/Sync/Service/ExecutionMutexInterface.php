<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/**
 * Serializes short execution state transitions for one interaction.
 * The implementation is expected to be called inside a DB transaction.
 */
interface ExecutionMutexInterface
{
    public function lock(SyncJobId $syncJobId): void;
}
