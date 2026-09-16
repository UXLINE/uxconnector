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
 * Writes one already field-mapped record produced by a SyncJob run
 * into its final destination.
 *
 * $context is passed on every call, rather than bound once at
 * construction: a single writer instance is shared across every
 * SyncJob a CronTask run processes (see ExecuteSyncJobService), and
 * each job may target a different GLPI itemtype with a different
 * reconciliation strategy.
 *
 * CORRECTIF (réconciliation, revue de code externe) : $context bundles
 * ExternalId alongside TargetItemtype/MatchField — an implementation
 * is now expected to reconcile via a stable external identifier
 * (ObjectLink) rather than a MatchField-based SQL lookup alone, which
 * risked silently matching the wrong GLPI record when several shared
 * the same value (see Infrastructure\Sync\Writer\GlpiItemWriter and
 * Domain\Sync\Entity\ObjectLink).
 *
 * Deliberately abstract about *what* the destination actually is:
 * for an INBOUND SyncJob it is a GLPI itemtype
 * (Infrastructure\Sync\Writer\GlpiItemWriter, restricted to
 * TargetItemtype's curated allow-list); for OUTBOUND it could be the
 * external system through HttpEngineInterface (not yet implemented —
 * this plugin currently only supports INBOUND synchronization end to
 * end).
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
