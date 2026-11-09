<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;

interface SyncJobExecutorInterface
{
    public function execute(ExecuteSyncJobCommand $command): SyncExecution;
}
