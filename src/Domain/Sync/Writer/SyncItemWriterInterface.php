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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Writer;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ReconciliationContext;

/**
 * Writes one field-mapped record into its destination using a per-call SyncWriteContext.
 * The writer may be shared between jobs, so target itemtype, entity and reconciliation
 * identity must not be cached from a previous call. ExternalId identifies the source record;
 * ObjectLink preserves stable reconciliation instead of relying on a match-field lookup alone.
 * The inbound adapter writes curated GLPI itemtypes. Outbound interactions use the separate
 * outbound execution service and HTTP engine.
 */
interface SyncItemWriterInterface
{
    /**
     * @param array<string, mixed> $mappedFields Keyed by FieldMapping::targetField().
     *
     * @return bool true if the record was written successfully.
     */
    public function write(ReconciliationContext $context, array $mappedFields): bool;
}
