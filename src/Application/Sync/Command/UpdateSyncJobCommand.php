<?php

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
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;

final readonly class UpdateSyncJobCommand
{
    /**
     * @param list<FieldMapping> $fieldMappings
     */
    public function __construct(
        public SyncJobId $id,
        public SyncJobName $name,
        public AuthConfigReference $authConfigReference,
        public SyncDirection $direction,
        public ResourcePath $resourcePath,
        public TargetItemtype $targetItemtype,
        public MatchField $matchField,
        public ExternalIdField $externalIdField,
        public array $fieldMappings,
        public ?EntityAssignmentStrategy $entityAssignmentStrategy,
        public CollectionPath $collectionPath,
        public PaginationConfig $paginationConfig,
        public InteractionTrigger $triggerType,
        public string $externalObjectType,
        public OutboundConfig $outboundConfig
    ) {
    }
}
