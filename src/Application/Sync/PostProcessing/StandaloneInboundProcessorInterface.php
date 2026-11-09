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

namespace GlpiPlugin\Uxconnector\Application\Sync\PostProcessing;

use GlpiPlugin\Uxconnector\Application\Sync\DTO\BatchProcessResult;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/**
 * For small external catalogs that require discovery before pagination.
 * The standard ticket/asset page pipeline remains unchanged.
 */
interface StandaloneInboundProcessorInterface
{
    public function supports(SyncJob $syncJob): bool;

    public function processStandalone(SyncJob $syncJob): BatchProcessResult;
}
