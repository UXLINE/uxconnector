<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Repository;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

/** Out-of-band cancellation flag, never overwritten by worker progress saves. */
interface ExecutionStopRequestRepositoryInterface
{
    public function requestStop(SyncExecutionId $id): bool;
    public function isStopRequested(SyncExecutionId $id): bool;
    public function clearStopRequest(SyncExecutionId $id): void;
}
