<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Command;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final readonly class ActivateSyncJobCommand
{
    public function __construct(
        public SyncJobId $syncJobId
    ) {}
}
