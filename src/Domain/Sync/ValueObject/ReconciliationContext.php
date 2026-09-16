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

namespace GlpiPlugin\Uxconnector\Domain\Sync\ValueObject;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;

/**
 * Bundles everything a SyncItemWriterInterface implementation needs
 * to reconcile one item against GLPI — kept as a single Value Object
 * rather than five separate write() parameters, since all of them
 * always travel together and are always sourced from the same
 * SyncJob.
 *
 * $entitiesId is already resolved (by ExecuteSyncJobService, via
 * SyncJob::entityAssignmentStrategy()) by the time it reaches this
 * Value Object — GlpiItemWriter never resolves an entity itself, it
 * only applies whatever value it is handed. `null` means "this
 * SyncJob has no entity strategy configured; leave entities_id to
 * GLPI's own default", not "entity 0/root" — see
 * EntityAssignmentStrategy's doc-block for the distinct meaning of a
 * *resolution* returning null (a failure signal, handled upstream by
 * ExecuteSyncJobService before a ReconciliationContext is ever built).
 */
final readonly class ReconciliationContext
{
   public function __construct(
        public SyncJobId $syncJobId,
        public AuthConfigReference $authConfigReference,
        public TargetItemtype $itemtype,
        public MatchField $matchField,
        public ExternalId $externalId,
        public string $externalObjectType,
        public ?int $entitiesId = null
    ) {
   }
}
