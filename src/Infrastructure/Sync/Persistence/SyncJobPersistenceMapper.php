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

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\UnknownEntityPolicy;
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

/**
 * Converts between the SyncJob aggregate (Domain) and the flat array
 * structure expected/returned by CommonDBTM (Infrastructure).
 *
 * The `field_mappings` column stores a JSON array - a FieldMapping
 * list has no individual row identity of its own (RULE-VALUEOBJECT-005 :
 * a Value Object has no mutable state, and here the *list* itself is
 * always replaced as a whole by SyncJob::changeFieldMappings()), so a
 * join table would add persistence complexity without a domain
 * benefit.
 */
final class SyncJobPersistenceMapper
{
    /**
     * @param array<string, mixed> $row Raw row as returned by CommonDBTM::fields.
     */
    public function toDomain(array $row): SyncJob
    {
        $this->assertHasRequiredFields($row);

        $direction = SyncDirection::tryFrom((string) $row['direction']);
        $status = SyncJobStatus::tryFrom((string) $row['status']);
        $targetItemtype = TargetItemtype::tryFrom((string) $row['target_itemtype']);

        if ($direction === null || $status === null || $targetItemtype === null) {
            throw new \RuntimeException(sprintf(
                'Corrupted SyncJob row #%s: unknown direction, status or target_itemtype value.',
                (string) $row['id'],
            ));
        }

        return SyncJob::reconstitute(
            new SyncJobId((int) $row['id']),
            new SyncJobName((string) $row['name']),
            new AuthConfigReference((int) $row['auth_config_reference']),
            $direction,
            new ResourcePath((string) $row['resource_path']),
            $targetItemtype,
            new MatchField((string) $row['match_field']),
            new ExternalIdField((string) $row['external_id_field']),
            $this->decodeFieldMappings((string) $row['field_mappings']),
            $status,
            new DateTimeImmutable((string) $row['date_creation']),
            new DateTimeImmutable((string) $row['date_mod']),
            $this->decodeEntityAssignmentStrategy($row['entity_assignment_strategy'] ?? null),
            new CollectionPath((string) ($row['collection_path'] ?? '$')),
            $this->decodePaginationConfig($row['pagination_config'] ?? null),
            isset($row['template_id']) && (string) $row['template_id'] !== '' ? (string) $row['template_id'] : null,
            isset($row['template_version']) && (string) $row['template_version'] !== ''
                ? (string) $row['template_version'] : null,
            InteractionTrigger::tryFrom((string) ($row['trigger_type'] ?? 'polling')) ?? InteractionTrigger::POLLING,
            (($row['external_object_type'] ?? '') === 'record' || trim(
                (string) ($row['external_object_type'] ?? '')
            ) === '')
                ? $targetItemtype->value
                : (string) $row['external_object_type'],
            $this->decodeOutboundConfig($row['outbound_config'] ?? null),
            isset($row['webhook_key']) && (string) $row['webhook_key'] !== '' ? (string) $row['webhook_key'] : null,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toPersistence(SyncJob $syncJob): array
    {
        $data = [
            'name'                   => $syncJob->name()->value(),
            'auth_config_reference'  => $syncJob->authConfigReference()->value(),
            'direction'              => $syncJob->direction()->value,
            'resource_path'          => $syncJob->resourcePath()->value(),
            'target_itemtype'        => $syncJob->targetItemtype()->value,
            'match_field'            => $syncJob->matchField()->value(),
            'external_id_field'      => $syncJob->externalIdField()->value(),
            'collection_path'        => $syncJob->collectionPath()->value(),
            'pagination_config'      => $this->encodePaginationConfig($syncJob->paginationConfig()),
            'template_id'            => $syncJob->templateId(),
            'template_version'       => $syncJob->templateVersion(),
            'trigger_type'           => $syncJob->triggerType()->value,
            'external_object_type'   => $syncJob->externalObjectType(),
            'outbound_config'        => $this->encodeOutboundConfig($syncJob->outboundConfig()),
            'webhook_key'            => $syncJob->webhookKey(),
            'field_mappings'         => $this->encodeFieldMappings($syncJob->fieldMappings()),
            'entity_assignment_strategy' => $this->encodeEntityAssignmentStrategy($syncJob->entityAssignmentStrategy()),
            'status'                 => $syncJob->status()->value,
        ];

        $id = $syncJob->id();

        if ($id !== null) {
            $data['id'] = $id->value();
        }

        return $data;
    }

    public function assignGeneratedId(SyncJob $syncJob, int $generatedId): void
    {
        $syncJob->assignId(new SyncJobId($generatedId));
    }

    /**
     * @param list<FieldMapping> $fieldMappings
     */
    private function encodeFieldMappings(array $fieldMappings): string
    {
        $rows = array_map(
            static fn(FieldMapping $mapping): array => [
                'source_field' => $mapping->sourceField(),
                'target_field' => $mapping->targetField(),
                'required'     => $mapping->isRequired(),
                'transform'    => $mapping->transform(),
            ],
            $fieldMappings,
        );

        try {
            return json_encode($rows, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Failed to encode FieldMapping list to JSON.',
                previous: $exception,
            );
        }
    }

    /**
     * @return list<FieldMapping>
     */
    private function decodeFieldMappings(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            /** @var list<array{source_field: string, target_field: string, required: bool, transform: ?string}> $rows */
            $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Failed to decode a persisted field_mappings JSON payload.',
                previous: $exception,
            );
        }

        return array_values(array_map(
            static fn(array $row): FieldMapping => new FieldMapping(
                $row['source_field'],
                $row['target_field'],
                (bool) ($row['required'] ?? false),
                $row['transform'] ?? null,
            ),
            $rows,
        ));
    }

    private function encodePaginationConfig(PaginationConfig $config): string
    {
        try {
            return json_encode($config->toArray(), JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Failed to encode PaginationConfig to JSON.', previous: $exception);
        }
    }

    private function decodePaginationConfig(mixed $json): PaginationConfig
    {
        if ($json === null || $json === '') {
            return PaginationConfig::none();
        }

        try {
            $payload = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Failed to decode persisted pagination_config JSON.', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new \RuntimeException('Corrupted pagination_config payload.');
        }

        $type = (string) ($payload['type'] ?? 'none');
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        return PaginationConfig::fromArray($type, $options);
    }

    private function encodeOutboundConfig(OutboundConfig $config): ?string
    {
        if (!$config->isConfigured()) {
            return null;
        }
        try {
            return json_encode($config->toArray(), JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Failed to encode outbound_config JSON.', previous: $exception);
        }
    }

    private function decodeOutboundConfig(mixed $json): OutboundConfig
    {
        if ($json === null || $json === '') {
            return OutboundConfig::none();
        }
        try {
            $payload = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Failed to decode outbound_config JSON.', previous: $exception);
        }
        return OutboundConfig::fromArray(is_array($payload) ? $payload : null);
    }

    private function encodeEntityAssignmentStrategy(?EntityAssignmentStrategy $strategy): ?string
    {
        if ($strategy === null) {
            return null;
        }

        if ($strategy->isFixed()) {
            $payload = [
                'type' => 'fixed',
                'entity_id' => $strategy->fixedEntityId(),
            ];
        } else {
            $payload = [
                'type' => 'mapped',
                'source_field' => $strategy->sourceField(),
                'mapping' => $strategy->valueToEntityId(),
                'unknown_policy' => $strategy->unknownPolicy()->value,
                'default_entity_id' => $strategy->defaultEntityId(),
            ];
        }

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Failed to encode EntityAssignmentStrategy to JSON.',
                previous: $exception,
            );
        }
    }

    private function decodeEntityAssignmentStrategy(?string $json): ?EntityAssignmentStrategy
    {
        if ($json === null || $json === '') {
            return null;
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Failed to decode a persisted entity_assignment_strategy JSON payload.',
                previous: $exception,
            );
        }

        if (($payload['type'] ?? null) === 'fixed') {
            return EntityAssignmentStrategy::fixed((int) $payload['entity_id']);
        }

        if (($payload['type'] ?? null) === 'mapped') {
            $unknownPolicy = UnknownEntityPolicy::tryFrom((string) ($payload['unknown_policy'] ?? ''))
                ?? UnknownEntityPolicy::FAIL;

            return EntityAssignmentStrategy::mapped(
                (string) $payload['source_field'],
                $payload['mapping'],
                $unknownPolicy,
                isset($payload['default_entity_id']) ? (int) $payload['default_entity_id'] : null,
            );
        }

        throw new \RuntimeException(sprintf(
            'Corrupted entity_assignment_strategy payload: unknown type "%s".',
            (string) ($payload['type'] ?? ''),
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertHasRequiredFields(array $row): void
    {
        foreach (
            ['id', 'name', 'auth_config_reference', 'direction', 'resource_path', 'target_itemtype', 'match_field',
                'external_id_field', 'field_mappings', 'status', 'date_creation', 'date_mod'] as $field
        ) {
            if (!array_key_exists($field, $row)) {
                throw new \RuntimeException(sprintf(
                    'Cannot hydrate SyncJob: missing "%s" field in persisted row.',
                    $field,
                ));
            }
        }
    }
}
