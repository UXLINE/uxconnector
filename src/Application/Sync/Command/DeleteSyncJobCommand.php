<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Command;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final readonly class DeleteSyncJobCommand
{
    public function __construct(public SyncJobId $syncJobId) {}
}
