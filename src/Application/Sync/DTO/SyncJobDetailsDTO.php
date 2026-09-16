<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\DTO;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;

/**
 * Read-only representation of the complete SyncJob configuration.
 *
 * No Domain object is exposed to the Presentation layer.
 */
final readonly class SyncJobDetailsDTO
{
   /**
    * @param list<array{
    *     sourceField: string,
    *     targetField: string,
    *     required: bool,
    *     transform: ?string
    * }> $fieldMappings
    */
   public function __construct(
      public int $id,
      public string $name,
      public int $authConfigReference,
      public string $direction,
      public string $directionLabel,
      public string $triggerType,
      public string $triggerTypeLabel,
      public string $resourcePath,
      public string $collectionPath,
      public string $targetItemtype,
      public string $targetItemtypeLabel,
      public string $matchField,
      public string $externalIdField,
      public string $externalObjectType,
      public array $fieldMappings,
      public string $paginationJson,
      public string $entityAssignmentJson,
      public string $outboundJson,
      public ?string $templateId,
      public ?string $templateVersion,
      public bool $webhookConfigured,
      public string $status,
      public string $statusLabel,
      public string $createdAt,
      public string $updatedAt
   ) {
   }

   public static function fromEntity(SyncJob $syncJob): self
   {
      $id = $syncJob->id();

      if ($id === null) {
         throw new \LogicException(
            'Cannot build SyncJobDetailsDTO from an unpersisted SyncJob.'
         );
      }

      $fieldMappings = array_values(array_map(
         static fn (FieldMapping $mapping): array => [
            'sourceField' => $mapping->sourceField(),
            'targetField' => $mapping->targetField(),
            'required' => $mapping->isRequired(),
            'transform' => $mapping->transform(),
         ],
         $syncJob->fieldMappings()
      ));

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

      return new self(
         id: $id->value(),
         name: $syncJob->name()->value(),
         authConfigReference: $syncJob->authConfigReference()->value(),
         direction: $syncJob->direction()->value,
         directionLabel: $syncJob->direction()->label(),
         triggerType: $syncJob->triggerType()->value,
         triggerTypeLabel: $syncJob->triggerType()->label(),
         resourcePath: $syncJob->resourcePath()->value(),
         collectionPath: $syncJob->collectionPath()->value(),
         targetItemtype: $syncJob->targetItemtype()->value,
         targetItemtypeLabel: $syncJob->targetItemtype()->label(),
         matchField: $syncJob->matchField()->value(),
         externalIdField: $syncJob->externalIdField()->value(),
         externalObjectType: $syncJob->externalObjectType(),
         fieldMappings: $fieldMappings,
         paginationJson: self::encodeJson(
            $syncJob->paginationConfig()->toArray()
         ),
         entityAssignmentJson: self::encodeJson(
            $entityAssignment ?? []
         ),
         outboundJson: self::encodeJson(
            $syncJob->outboundConfig()->toArray()
         ),
         templateId: $syncJob->templateId(),
         templateVersion: $syncJob->templateVersion(),
         webhookConfigured: $syncJob->webhookKey() !== null,
         status: $syncJob->status()->value,
         statusLabel: $syncJob->status()->label(),
         createdAt: $syncJob->createdAt()->format('Y-m-d H:i:s'),
         updatedAt: $syncJob->updatedAt()->format('Y-m-d H:i:s')
      );
   }

   /**
    * @param array<mixed> $payload
    */
   private static function encodeJson(array $payload): string
   {
      try {
         return json_encode(
            $payload,
            JSON_THROW_ON_ERROR
            | JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
         );
      } catch (\JsonException $exception) {
         throw new \RuntimeException(
            'Unable to serialize SyncJob configuration.',
            previous: $exception
         );
      }
   }
}
