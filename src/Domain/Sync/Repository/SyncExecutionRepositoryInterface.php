<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Repository;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

interface SyncExecutionRepositoryInterface
{
    public function save(SyncExecution $execution): SyncExecution;
    public function findById(SyncExecutionId $id): ?SyncExecution;
    /** @return list<SyncExecution> */
    public function findBySyncJobId(SyncJobId $syncJobId): array;
    /** @return list<SyncExecution> */
    public function findPending(int $limit = 20): array;
    /** @return list<SyncExecution> */
    public function findRunning(int $limit = 100): array;
    public function hasOpenExecution(SyncJobId $syncJobId): bool;
    public function delete(SyncExecutionId $id): void;
}
