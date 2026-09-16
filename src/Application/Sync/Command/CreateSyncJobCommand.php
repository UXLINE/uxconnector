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

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\OutboundConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;

/**
 * Input contract of the "create a SyncJob" use case.
 *
 * A SyncJob is always created in DRAFT status. Its FieldMapping list must
 * already contain the configured MatchField because first reconciliation
 * cannot be performed safely without that mapped business key. The synthetic
 * asset_identity matcher is the exception: it is backed by at least one mapped
 * identity field (uuid, serial, otherserial or name). Activation is still a
 * separate, later lifecycle step (SyncJob::activate()).
 */
final readonly class CreateSyncJobCommand
{
    /**
     * @param list<FieldMapping> $fieldMappings
     */
   public function __construct(
        public SyncJobName $name,
        public AuthConfigReference $authConfigReference,
        public SyncDirection $direction,
        public ResourcePath $resourcePath,
        public TargetItemtype $targetItemtype,
        public MatchField $matchField,
        public ExternalIdField $externalIdField,
        public array $fieldMappings = [],
        public ?EntityAssignmentStrategy $entityAssignmentStrategy = null,
        public ?CollectionPath $collectionPath = null,
        public ?PaginationConfig $paginationConfig = null,
        public ?string $templateId = null,
        public ?string $templateVersion = null,
        public ?InteractionTrigger $triggerType = null,
        public ?string $externalObjectType = null,
        public ?OutboundConfig $outboundConfig = null,
        public ?string $webhookKey = null
    ) {
   }
}
