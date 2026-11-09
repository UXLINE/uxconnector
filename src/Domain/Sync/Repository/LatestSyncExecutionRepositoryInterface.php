<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Repository;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

interface LatestSyncExecutionRepositoryInterface
{
    public function findLatestBySyncJobId(SyncJobId $id): ?SyncExecution;
}
