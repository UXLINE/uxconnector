<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Repository;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/** Persists the opaque continuation cursor of a polling synchronization. */
interface SyncCheckpointRepositoryInterface
{
    public function findCursor(SyncJobId $syncJobId): ?string;

    public function saveCursor(SyncJobId $syncJobId, string $cursor): void;

    public function delete(SyncJobId $syncJobId): void;
}
