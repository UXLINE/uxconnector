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

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\UnknownEntityPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy;

/**
 * Converts the entity-assignment section of the SyncJob creation form
 * into an EntityAssignmentStrategy|null.
 *
 * Kept as a standalone class rather than inlined in
 * SyncJobController::create() so it can be unit-tested without any
 * GLPI dependency - same rationale as FieldMappingRequestParser.
 *
 * Configuration errors fail closed. Silently converting a malformed
 * multi-entity strategy into "no strategy" can write synchronized
 * records into an unintended GLPI entity, which is a data-isolation
 * risk rather than a harmless form convenience. Blank strategy still
 * means "no strategy"; once FIXED or MAPPED is selected, its complete
 * configuration is mandatory.
 */
final class EntityAssignmentStrategyRequestParser
{
    /**
     * @param array<string, mixed> $request Typically $_POST.
     */
    public function parse(array $request): ?EntityAssignmentStrategy
    {
        $strategy = trim((string) ($request['entity_strategy'] ?? ''));

        return match ($strategy) {
            '' => null,
            'fixed' => $this->parseFixed($request),
            'mapped' => $this->parseMapped($request),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown entity assignment strategy "%s".',
                $strategy,
            )),
        };
    }

    /** @param array<string, mixed> $request */
    private function parseFixed(array $request): EntityAssignmentStrategy
    {
        $rawId = trim((string) ($request['entity_fixed_id'] ?? ''));

        if (!$this->isNonNegativeInteger($rawId)) {
            throw new \InvalidArgumentException(
                'Fixed entity strategy requires a non-negative integer GLPI entity ID.',
            );
        }

        return EntityAssignmentStrategy::fixed((int) $rawId);
    }

    /** @param array<string, mixed> $request */
    private function parseMapped(array $request): EntityAssignmentStrategy
    {
        $sourceField = trim((string) ($request['entity_source_field'] ?? ''));

        if ($sourceField === '') {
            throw new \InvalidArgumentException(
                'Mapped entity strategy requires a source field.',
            );
        }

        $mapping = $this->parseMappingRules((string) ($request['entity_mapping_rules'] ?? ''));

        if ($mapping === []) {
            throw new \InvalidArgumentException(
                'Mapped entity strategy requires at least one valid mapping rule.',
            );
        }

        $rawPolicy = trim((string) ($request['entity_unknown_policy'] ?? 'fail'));
        $unknownPolicy = UnknownEntityPolicy::tryFrom($rawPolicy);

        if ($unknownPolicy === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown entity fallback policy "%s".',
                $rawPolicy,
            ));
        }

        $defaultId = null;
        $rawDefaultId = trim((string) ($request['entity_default_id'] ?? ''));

        if ($rawDefaultId !== '') {
            if (!$this->isNonNegativeInteger($rawDefaultId)) {
                throw new \InvalidArgumentException(
                    'Default entity ID must be a non-negative integer.',
                );
            }

            $defaultId = (int) $rawDefaultId;
        }

        if ($unknownPolicy === UnknownEntityPolicy::USE_DEFAULT && $defaultId === null) {
            throw new \InvalidArgumentException(
                'The USE_DEFAULT entity policy requires a default GLPI entity ID.',
            );
        }

        return EntityAssignmentStrategy::mapped($sourceField, $mapping, $unknownPolicy, $defaultId);
    }

    /**
     * Parses one "SourceValue=EntityId" rule per non-empty line.
     * Malformed input fails closed: silently ignoring a typo could route
     * synchronized records to the wrong entity.
     *
     * @return array<string, int>
     */
    private function parseMappingRules(string $raw): array
    {
        $mapping = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $index => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (!str_contains($line, '=')) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid entity mapping rule on line %d: expected "source value = entity ID".',
                    $index + 1,
                ));
            }

            [$sourceValue, $entityId] = explode('=', $line, 2);
            $sourceValue = trim($sourceValue);
            $entityId = trim($entityId);

            if ($sourceValue === '' || !$this->isNonNegativeInteger($entityId)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid entity mapping rule on line %d.',
                    $index + 1,
                ));
            }

            if (array_key_exists($sourceValue, $mapping)) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate entity mapping source value "%s".',
                    $sourceValue,
                ));
            }

            $mapping[$sourceValue] = (int) $entityId;
        }

        return $mapping;
    }

    private function isNonNegativeInteger(string $value): bool
    {
        return $value !== '' && preg_match('/^\d+$/D', $value) === 1;
    }
}
