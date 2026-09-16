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

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;

/** Vendor-specific enrichment hook executed only after the generic item write succeeds. */
interface InboundRecordPostProcessorInterface
{
   public function supports(SyncJob $syncJob): bool;

   /**
    * @param array<string, mixed> $sourceRecord
    */
   public function process(SyncJob $syncJob, array $sourceRecord, ExternalId $externalId): bool;
}
