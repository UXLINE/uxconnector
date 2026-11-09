<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Retry;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncFailure;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/**
 * Application port used by failed-item retries to reload the current source
 * object before replaying it.
 *
 * Returning null means that this connector/object type has no dedicated
 * single-record refresh strategy and the caller may fall back to the
 * historical snapshot for backward compatibility. Implementations MUST throw
 * when they support the object type but the live source object cannot be read:
 * a stale snapshot must not silently override a failed live refresh.
 */
interface RetrySourceRecordRefresherInterface
{
    /** @return array<string,mixed>|null */
    public function refresh(SyncJob $syncJob, SyncFailure $failure): ?array;
}
