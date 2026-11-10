<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\DTO;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;

final readonly class SyncJobEditDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public int $authConfigReference,
        public string $interactionMode,
        public string $direction,
        public string $triggerType,
        public string $resourcePath,
        public string $collectionPath,
        public string $targetItemtype,
        public string $externalObjectType,
        public string $matchField,
        public string $externalIdField,
        public string $fieldMappingsJson,
        public string $paginationJson,
        public string $entityAssignmentJson,
        public string $outboundJson,
        public string $status,
        public string $statusLabel
    ) {
    }

    public static function fromEntity(SyncJob $syncJob): self
    {
        $id = $syncJob->id();

        if ($id === null) {
            throw new \LogicException(
                'Cannot edit a SyncJob that has not been persisted.',
            );
        }

        $fieldMappings = [];

        foreach ($syncJob->fieldMappings() as $mapping) {
            $fieldMappings[] = [
                'source_field' => $mapping->sourceField(),
                'target_field' => $mapping->targetField(),
                'required' => $mapping->isRequired(),
                'transform' => $mapping->transform(),
            ];
        }

        $entityAssignment = null;
        $strategy = $syncJob->entityAssignmentStrategy();

        if ($strategy !== null) {
            if ($strategy->isFixed()) {
                $entityAssignment = [
                    'type' => 'fixed',
                    'entity_id' => $strategy->fixedEntityId(),
                ];
            } else {
                $entityAssignment = [
                    'type' => 'mapped',
                    'source_field' => $strategy->sourceField(),
                    'mapping' => $strategy->valueToEntityId(),
                    'unknown_policy' => $strategy->unknownPolicy()->value,
                    'default_entity_id' => $strategy->defaultEntityId(),
                ];
            }
        }

        $interactionMode = match (true) {
            $syncJob->direction() === SyncDirection::OUTBOUND => 'outbound_glpi_event',
            $syncJob->triggerType()->value === 'webhook' => 'inbound_webhook',
            default => 'inbound_polling',
        };

        return new self(
            id: $id->value(),
            name: $syncJob->name()->value(),
            authConfigReference: $syncJob->authConfigReference()->value(),
            interactionMode: $interactionMode,
            direction: $syncJob->direction()->value,
            triggerType: $syncJob->triggerType()->value,
            resourcePath: $syncJob->resourcePath()->value(),
            collectionPath: $syncJob->collectionPath()->value(),
            targetItemtype: $syncJob->targetItemtype()->value,
            externalObjectType: $syncJob->externalObjectType(),
            matchField: $syncJob->matchField()->value(),
            externalIdField: $syncJob->externalIdField()->value(),
            fieldMappingsJson: self::encodeJson($fieldMappings),
            paginationJson: self::encodeJson(
                $syncJob->paginationConfig()->toArray(),
            ),
            entityAssignmentJson: self::encodeJson(
                $entityAssignment,
            ),
            outboundJson: self::encodeJson(
                $syncJob->outboundConfig()->isConfigured()
                 ? $syncJob->outboundConfig()->toArray()
                 : [],
            ),
            status: $syncJob->status()->value,
            statusLabel: $syncJob->status()->label(),
        );
    }

    private static function encodeJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Unable to serialize synchronization configuration.',
                previous: $exception,
            );
        }
    }
}
