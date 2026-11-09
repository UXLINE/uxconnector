<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

final class ListSyncFailuresService
{
    public function __construct(private readonly SyncFailureRepositoryInterface $repository) {}
    public function execute(SyncExecutionId $id): array
    {
        return $this->repository->findByExecution($id);
    }
}
