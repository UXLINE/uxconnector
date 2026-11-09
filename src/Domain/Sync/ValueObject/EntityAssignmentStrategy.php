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

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\UnknownEntityPolicy;

/**
 * Determines which GLPI entity (`entities_id`) a synchronized record
 * should be assigned to - the gap identified by the code review as
 * critical for any multi-entity GLPI deployment (HELPLINE's own case)
 * : before this Value Object, neither `SyncJob` nor `GlpiItemWriter`
 * had any notion of entity at all.
 *
 * Two strategies, matching the review's proposed design:
 *
 * - FIXED : every record synchronized by this SyncJob goes to one
 *   single, statically configured entity.
 * - MAPPED : a field of the *raw* source item (before any
 *   FieldMapping - same convention as ExternalIdField) is looked up
 *   in a source-value → entities_id table (e.g. "Nantes" → 42,
 *   "Paris" → 68). UnknownEntityPolicy governs what happens when the
 *   source value has no entry in that table.
 *
 * `resolve(): ?int` returns `null` exclusively as a failure signal -
 * FIXED never returns null by construction, and MAPPED only returns
 * null when the source value is unmapped AND the policy is FAIL. A
 * caller can therefore treat a null result as "this item cannot be
 * reconciled, count it as failed" without needing to know which
 * strategy produced it.
 */
final readonly class EntityAssignmentStrategy
{
    private function __construct(
        private bool $isFixed,
        private ?int $fixedEntityId,
        private ?string $sourceField,
        /** @var array<string, int> */
        private array $valueToEntityId,
        private UnknownEntityPolicy $unknownPolicy,
        private ?int $defaultEntityId
    ) {}

    public static function fixed(int $entityId): self
    {
        if ($entityId < 0) {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidEntityAssignmentStrategyException::fixedEntityIdMustBePositive();
        }

        return new self(true, $entityId, null, [], UnknownEntityPolicy::FAIL, null);
    }

    /**
     * @param array<string, int> $valueToEntityId Source value (as found in the
     *        raw item under $sourceField) => target entities_id.
     */
    public static function mapped(
        string $sourceField,
        array $valueToEntityId,
        UnknownEntityPolicy $unknownPolicy = UnknownEntityPolicy::FAIL,
        ?int $defaultEntityId = null
    ): self {
        if (trim($sourceField) === '') {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidEntityAssignmentStrategyException::mappedSourceFieldMustNotBeEmpty();
        }

        if ($valueToEntityId === []) {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidEntityAssignmentStrategyException::mappedTableMustNotBeEmpty();
        }

        if ($unknownPolicy === UnknownEntityPolicy::USE_DEFAULT && $defaultEntityId === null) {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidEntityAssignmentStrategyException::defaultEntityIdRequiredForUseDefaultPolicy();
        }

        return new self(false, null, $sourceField, $valueToEntityId, $unknownPolicy, $defaultEntityId);
    }

    /**
     * @param array<string, mixed> $rawItem The item exactly as returned by the
     *        external system, before any FieldMapping is applied.
     *
     * @return int|null The resolved entities_id, or null if it could not be
     *         resolved (MAPPED strategy, unmapped value, FAIL policy) - the
     *         caller must treat null as "fail this item", never as "0".
     */
    public function resolve(array $rawItem): ?int
    {
        if ($this->isFixed) {
            return $this->fixedEntityId;
        }

        $rawValue = $this->readSourceValue($rawItem, (string) $this->sourceField);

        if ($rawValue !== null && array_key_exists((string) $rawValue, $this->valueToEntityId)) {
            return $this->valueToEntityId[(string) $rawValue];
        }

        return match ($this->unknownPolicy) {
            UnknownEntityPolicy::FAIL => null,
            UnknownEntityPolicy::USE_DEFAULT => $this->defaultEntityId,
        };
    }

    public function isFixed(): bool
    {
        return $this->isFixed;
    }

    public function fixedEntityId(): ?int
    {
        return $this->fixedEntityId;
    }

    public function sourceField(): ?string
    {
        return $this->sourceField;
    }

    /**
     * @return array<string, int>
     */
    public function valueToEntityId(): array
    {
        return $this->valueToEntityId;
    }

    public function unknownPolicy(): UnknownEntityPolicy
    {
        return $this->unknownPolicy;
    }

    public function defaultEntityId(): ?int
    {
        return $this->defaultEntityId;
    }
    /**
     * Reads a dotted source path (e.g. fields.project.key) without coupling
     * the Domain value object to the Application JsonPathValueReader.
     *
     * @param array<string, mixed> $rawItem
     */
    private function readSourceValue(array $rawItem, string $path): mixed
    {
        if (str_starts_with($path, '$.')) {
            $path = substr($path, 2);
        }

        $current = $rawItem;
        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || !is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

}
