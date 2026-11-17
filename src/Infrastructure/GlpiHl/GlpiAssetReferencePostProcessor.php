<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiHl;

use DBmysql;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\InboundRecordPostProcessorInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime\SyncRuntimeGuard;

final class GlpiAssetReferencePostProcessor implements InboundRecordPostProcessorInterface
{
    /** @var array<string, int> */
    private array $namedReferenceCache = [];

    /** @var array<string, int> */
    private array $stateCache = [];

    /** @var array<string, int> */
    private array $locationCache = [];

    public function __construct(
        private readonly DBmysql $db,
        private readonly ObjectLinkRepositoryInterface $objectLinks
    ) {
    }

    public function supports(SyncJob $syncJob): bool
    {
        $templateId = $syncJob->templateId();

        return $templateId !== null
            && str_starts_with($templateId, 'glpi-hl.assets.')
            && GlpiAssetReferenceDefinition::supports($syncJob->targetItemtype());
    }

    /** @param array<string, mixed> $sourceRecord */
    public function process(SyncJob $syncJob, array $sourceRecord, ExternalId $externalId): bool
    {
        $definition = GlpiAssetReferenceDefinition::for($syncJob->targetItemtype());
        if ($definition === null) {
            return true;
        }

        $assetLink = $this->objectLinks->findByExternalId(
            $syncJob->authConfigReference(),
            $syncJob->externalObjectType(),
            $externalId,
        );
        if ($assetLink === null || $assetLink->itemtype() !== $syncJob->targetItemtype()) {
            return false;
        }

        $assetClass = $syncJob->targetItemtype()->glpiClassName();
        /** @var \CommonDBTM $asset */
        $asset = \GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\NativeItemFactory::create($assetClass);
        if (!$asset->getFromDB($assetLink->itemId())) {
            return false;
        }

        $update = ['id' => $assetLink->itemId()];
        $entityId = $this->currentEntityId($asset);

        // Source entity names are descriptive only. The job controls placement;
        // a post-processor must neither create entities nor attach a foreign location.
        $configured = $syncJob->entityAssignmentStrategy()?->resolve($sourceRecord);
        if ($configured !== null && $configured !== $entityId) {
            return false;
        }

        if ($this->hasNamedReference($sourceRecord['status'] ?? null)) {
            $id = $this->resolveState($sourceRecord['status']);
            if ($id === null) {
                return false;
            }
            $this->queueChangedReference($update, $asset, 'states_id', $id);
        }

        if ($this->hasNamedReference($sourceRecord['manufacturer'] ?? null)) {
            $id = $this->resolveNamedReference(\Manufacturer::class, $sourceRecord['manufacturer']);
            if ($id === null) {
                return false;
            }
            $this->queueChangedReference($update, $asset, 'manufacturers_id', $id);
        }

        if ($this->hasNamedReference($sourceRecord['type'] ?? null)) {
            $id = $this->resolveNamedReference($definition['type_class'], $sourceRecord['type']);
            if ($id === null) {
                return false;
            }
            $this->queueChangedReference($update, $asset, $definition['type_field'], $id);
        }

        if ($this->hasNamedReference($sourceRecord['model'] ?? null)) {
            $id = $this->resolveNamedReference($definition['model_class'], $sourceRecord['model']);
            if ($id === null) {
                return false;
            }
            $this->queueChangedReference($update, $asset, $definition['model_field'], $id);
        }

        if ($this->hasNamedReference($sourceRecord['location'] ?? null)) {
            $id = $this->resolveLocation($sourceRecord['location'], $entityId);
            if ($id === null) {
                return false;
            }
            $this->queueChangedReference($update, $asset, 'locations_id', $id);
        }

        if (count($update) === 1) {
            return true;
        }

        return SyncRuntimeGuard::run(static fn(): bool => (bool) $asset->update($update));
    }

    private function currentEntityId(\CommonDBTM $asset): int
    {
        $value = filter_var($asset->fields['entities_id'] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0) {
            throw new \RuntimeException('Asset entity is unavailable.');
        }
        return $value;
    }

    private function hasNamedReference(mixed $reference): bool
    {
        return is_array($reference)
            && isset($reference['name'])
            && is_scalar($reference['name'])
            && trim((string) $reference['name']) !== '';
    }

    /**
     * @param class-string<\CommonDBTM> $className
     * @param array<string, mixed> $reference
     */
    private function resolveNamedReference(string $className, array $reference): ?int
    {
        $name = $this->referenceName($reference);
        if ($name === null) {
            return null;
        }

        $cacheKey = $className . '|' . $this->normalizeCacheValue($name);
        if (isset($this->namedReferenceCache[$cacheKey])) {
            return $this->namedReferenceCache[$cacheKey];
        }

        /** @var \CommonDBTM $item */
        $item = \GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\NativeItemFactory::create($className);
        $existing = $this->findUniqueId($item->getTable(), ['name' => $name]);
        if ($existing !== null) {
            $this->namedReferenceCache[$cacheKey] = $existing;
            return $existing;
        }

        $generatedId = SyncRuntimeGuard::run(static fn() => $item->add(['name' => $name]));
        if ($generatedId === false) {
            return null;
        }

        $id = (int) $generatedId;
        $this->namedReferenceCache[$cacheKey] = $id;
        return $id;
    }

    /** @param array<string, mixed> $reference */
    private function resolveState(array $reference): ?int
    {
        $name = $this->referenceName($reference);
        if ($name === null) {
            return null;
        }

        $segments = $this->splitPath($this->referencePath($reference, $name));
        if ($segments === []) {
            return null;
        }

        $cacheKey = $this->normalizeCacheValue(implode(' > ', $segments));
        if (isset($this->stateCache[$cacheKey])) {
            return $this->stateCache[$cacheKey];
        }

        $parentStateId = 0;
        foreach ($segments as $segment) {
            $state = new \State();
            $existing = $this->findUniqueId($state->getTable(), [
                'name' => $segment,
                'states_id' => $parentStateId,
            ]);
            if ($existing !== null) {
                $parentStateId = $existing;
                continue;
            }

            $input = [
                'name' => $segment,
                'states_id' => $parentStateId,
                'is_helpdesk_visible' => 1,
            ];

            // State::getvisibilityFields() is protected in GLPI 11. Mirror
            // the core convention instead of calling a non-public API so a
            // state created by the consolidation is visible for every native
            // asset type declared by GLPI.
            global $CFG_GLPI;
            $stateTypes = $CFG_GLPI['state_types'] ?? [];
            if (!is_array($stateTypes)) {
                $stateTypes = [];
            }
            foreach ($stateTypes as $visibleItemtype) {
                if (!is_string($visibleItemtype) || $visibleItemtype === '') {
                    continue;
                }
                $input['is_visible_' . strtolower($visibleItemtype)] = 1;
            }

            $generatedId = SyncRuntimeGuard::run(static fn() => $state->add($input));
            if ($generatedId === false) {
                return null;
            }
            $parentStateId = (int) $generatedId;
        }

        $this->stateCache[$cacheKey] = $parentStateId;
        return $parentStateId;
    }

    /** @param array<string, mixed> $reference */
    private function resolveLocation(array $reference, int $entityId): ?int
    {
        $name = $this->referenceName($reference);
        if ($name === null) {
            return null;
        }

        $segments = $this->splitPath($this->referencePath($reference, $name));
        if ($segments === []) {
            return null;
        }

        $cacheKey = $entityId . '|' . $this->normalizeCacheValue(implode(' > ', $segments));
        if (isset($this->locationCache[$cacheKey])) {
            return $this->locationCache[$cacheKey];
        }

        $parentLocationId = 0;
        foreach ($segments as $segment) {
            $location = new \Location();
            $criteria = [
                'name' => $segment,
                'entities_id' => $entityId,
                'locations_id' => $parentLocationId,
            ];
            $existing = $this->findUniqueId($location->getTable(), $criteria);
            if ($existing !== null) {
                $parentLocationId = $existing;
                continue;
            }

            $generatedId = SyncRuntimeGuard::run(static fn() => $location->add([
                'name' => $segment,
                'entities_id' => $entityId,
                'locations_id' => $parentLocationId,
                'is_recursive' => 0,
            ]));
            if ($generatedId === false) {
                return null;
            }
            $parentLocationId = (int) $generatedId;
        }

        $this->locationCache[$cacheKey] = $parentLocationId;
        return $parentLocationId;
    }

    /** @param array<string, mixed> $reference */
    private function referenceName(array $reference): ?string
    {
        if (!isset($reference['name']) || !is_scalar($reference['name'])) {
            return null;
        }

        $name = trim((string) $reference['name']);
        return $name === '' ? null : $name;
    }

    /** @param array<string, mixed> $reference */
    private function referencePath(array $reference, string $fallback): string
    {
        if (isset($reference['completename']) && is_scalar($reference['completename'])) {
            $value = trim((string) $reference['completename']);
            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    /** @return list<string> */
    private function splitPath(string $path): array
    {
        $parts = preg_split('/\s*>\s*/u', trim($path));
        if (!is_array($parts)) {
            return [];
        }

        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $result[] = $part;
            }
        }
        return $result;
    }

    private function sameName(string $left, string $right): bool
    {
        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }

    /** @param array<string, mixed> $update */
    private function queueChangedReference(array &$update, \CommonDBTM $asset, string $field, int $targetId): void
    {
        $current = $asset->fields[$field] ?? 0;
        $currentId = is_numeric($current) ? (int) $current : 0;
        if ($field !== 'entities_id' && $currentId === 0 && $targetId > 0) {
            $update[$field] = $targetId;
        }
    }

    private function normalizeCacheValue(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** @param array<string, int|string> $where */
    private function findUniqueId(string $table, array $where): ?int
    {
        $result = $this->db->request([
            'SELECT' => 'id',
            'FROM' => $table,
            'WHERE' => $where,
        ]);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = (int) $row['id'];
        }

        if (count($ids) > 1) {
            throw new \RuntimeException(sprintf(
                'Ambiguous GLPI reference in table "%s" for criteria %s.',
                $table,
                json_encode($where, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));
        }

        return $ids[0] ?? null;
    }
}
