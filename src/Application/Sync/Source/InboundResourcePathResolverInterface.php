<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Source;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

interface InboundResourcePathResolverInterface
{
    public function supports(SyncJob $syncJob): bool;
    public function resolve(AuthConfig $authConfig, SyncJob $syncJob, string $path): string;
}
