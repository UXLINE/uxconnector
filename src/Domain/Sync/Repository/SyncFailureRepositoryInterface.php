<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Repository;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncFailure;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

interface SyncFailureRepositoryInterface
{
    public function add(SyncFailure $failure): void;
    /** @return list<SyncFailure> */
    public function findByExecution(SyncExecutionId $executionId): array;
    public function updateRetryStatus(int $failureId, string $status): void;
    public function queueByExecution(SyncExecutionId $executionId): int;
    /** @return list<SyncFailure> */
    public function findQueued(int $limit = 50): array;
}
