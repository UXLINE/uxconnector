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
 * Vendor-specific batch processor executed instead of the generic item writer.
 *
 * It is reserved for source payloads that need to aggregate several records
 * before one GLPI reference object can be created (for example distinct
 * Zendesk topics discovered across many tickets).
 */
interface InboundBatchProcessorInterface
{
    public function supports(SyncJob $syncJob): bool;

    /** @param list<mixed> $items */
    public function process(SyncJob $syncJob, array $items): BatchProcessResult;
}
