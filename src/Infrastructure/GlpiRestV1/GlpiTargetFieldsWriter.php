<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use DBmysql;

/** Writes already-created Fields-plugin values without altering GLPI core tables. */
final class GlpiTargetFieldsWriter
{
    /** Exact labels observed on INDIGO's NetworkEquipment target schema. */
    private const NETWORK_LABEL_ALIASES = [
        'Entité Inpark' => 'Code Inpark Entité',
        'Code DA' => 'Code Inpark Code DA',
        'Code DA Comptable' => 'Code Inpark Code DA Comptable',
        'Code ETS' => 'Code Inpark Code ETS',
        'Code DB' => 'Code Inpark Code DB',
        'Code NA' => 'Code Inpark Code NA',
        'Code NB' => 'Code Inpark Code NB',
    ];

    /** @var array<string,array<string,array{container_id:int,container_name:string,field_name:string,type:string}>> */
    private array $metadata = [];

    public function __construct(private readonly DBmysql $db) {}

    /** @return list<string> */
    public function writableTargetsFor(string $itemtype): array
    {
        return array_map(
            static fn(string $label): string => 'fields:' . $label,
            array_keys($this->metadataFor($itemtype)),
        );
    }

    /** @param list<string> $labels */
    public function assertAvailable(string $itemtype, array $labels): void
    {
        foreach ($this->resolveMetadata($itemtype, $labels) as $meta) {
            $this->assertGeneratedStorage($itemtype, $meta);
        }
    }

    /** @param array<string,mixed> $values */
    public function write(string $itemtype, int $itemId, array $values): bool
    {
        return \GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime\SyncRuntimeGuard::run(
            fn(): bool => $this->writeNative($itemtype, $itemId, $values),
        );
    }

    /** @param array<string,mixed> $values */
    private function writeNative(string $itemtype, int $itemId, array $values): bool
    {
        $values = array_filter($values, static fn(mixed $v): bool => $v !== null && $v !== '');
        if ($values === []) {
            return false;
        }
        $metadata = $this->resolveMetadata($itemtype, array_keys($values));

        $byContainer = [];
        $addressed = [];
        foreach ($values as $label => $value) {
            $meta = $metadata[$label];
            $address = $meta['container_id'] . ':' . $meta['field_name'];
            if (isset($addressed[$address])) {
                throw new \RuntimeException('Several mapped labels address the same target Fields attribute.');
            }
            $addressed[$address] = true;
            $byContainer[$meta['container_id']]['meta'] = $meta;
            $this->assertGeneratedStorage($itemtype, $meta);
            $column = $this->columnName($meta);
            $byContainer[$meta['container_id']]['values'][$column] = $this->normalizeFieldValue($value, $meta);
        }

        $changed = false;
        foreach ($byContainer as $containerId => $definition) {
            /** @var array{container_id:int,container_name:string,field_name:string,type:string} $meta */
            $meta = $definition['meta'];
            $class = \PluginFieldsContainer::getClassname($itemtype, $meta['container_name']);
            if (!class_exists($class)) {
                throw new \RuntimeException(sprintf('Fields generated class "%s" is unavailable. Regenerate the Fields plugin files.', $class));
            }
            $instance = \GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\NativeItemFactory::create($class);
            $table = $class::getTable();
            $existing = $this->firstRow($table, [
                'items_id' => $itemId,
                'itemtype' => $itemtype,
                'plugin_fields_containers_id' => (int) $containerId,
            ]);
            $payload = [
                'items_id' => $itemId,
                'itemtype' => $itemtype,
                'plugin_fields_containers_id' => (int) $containerId,
                ...$definition['values'],
            ];
            if ($existing === null) {
                $id = $instance->add($payload);
                if (!$id) {
                    throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\ProfileMigrationException('TARGET_FIELDS_CREATE_FAILED');
                }
                $changed = true;
                continue;
            }
            $delta = [];
            foreach ($definition['values'] as $field => $value) {
                if (\GlpiPlugin\Uxconnector\Domain\Sync\Policy\ExistingValuePolicy::isEmpty($field, $existing[$field] ?? null)
                    && !\GlpiPlugin\Uxconnector\Domain\Sync\Policy\ExistingValuePolicy::isEmpty($field, $value)) {
                    $delta[$field] = $value;
                }
            }
            if ($delta !== []) {
                $delta['id'] = (int) $existing['id'];
                if (!$instance->update($delta)) {
                    throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\ProfileMigrationException('TARGET_FIELDS_UPDATE_FAILED');
                }
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Resolve aliases without altering stored labels, generated names or schema.
     * Both preflight and writing use this method, so accepting a label cannot
     * hide an unavailable write target. The editor still lists only real labels.
     *
     * @param list<string> $labels
     * @return array<string,array{container_id:int,container_name:string,field_name:string,type:string}>
     */
    private function resolveMetadata(string $itemtype, array $labels): array
    {
        $metadata = $this->metadataFor($itemtype);
        $resolved = [];
        $missing = [];
        foreach ($labels as $label) {
            $candidates = [$label];
            if ($itemtype === 'NetworkEquipment') {
                foreach (self::NETWORK_LABEL_ALIASES as $canonical => $alias) {
                    if ($label === $canonical || $label === $alias) {
                        $candidates = [$canonical, $alias];
                        break;
                    }
                }
            }
            if ($itemtype === 'SoftwareLicense' && in_array($label, ["Date d’activation", 'Date de désactivation'], true)) {
                $normalized = static fn(string $text): string => mb_strtolower(str_replace('’', "'", $text));
                $candidates = array_values(array_unique(array_merge($candidates, array_keys(array_filter(
                    $metadata,
                    static fn(array $meta, string $candidate): bool => $normalized($candidate) === $normalized($label),
                    ARRAY_FILTER_USE_BOTH,
                )))));
            }
            $matches = [];
            foreach ($candidates as $candidate) {
                if (isset($metadata[$candidate])) {
                    $matches[] = $metadata[$candidate];
                }
            }
            if (count($matches) > 1) {
                throw new \RuntimeException(sprintf('Target Fields label "%s" is ambiguous for itemtype %s: canonical and alias fields both exist.', $label, $itemtype));
            }
            if ($matches === []) {
                $missing[] = $label;
            } else {
                $resolved[$label] = $matches[0];
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'Missing target Fields configuration for %s: %s. Check active fields, object type and supported labels before running this GLPI migration job.',
                $itemtype,
                implode(', ', $missing),
            ));
        }
        return $resolved;
    }

    /** @return array<string,array{container_id:int,container_name:string,field_name:string,type:string}> */
    private function metadataFor(string $itemtype): array
    {
        if (isset($this->metadata[$itemtype])) {
            return $this->metadata[$itemtype];
        }
        if (!$this->db->tableExists('glpi_plugin_fields_containers') || !$this->db->tableExists('glpi_plugin_fields_fields') || !class_exists('PluginFieldsContainer')) {
            throw new \RuntimeException('The GLPI Fields plugin must be installed and active on the target instance.');
        }
        $result = [];
        $containers = $this->db->request([
            'SELECT' => ['id', 'name', 'itemtypes'],
            'FROM' => 'glpi_plugin_fields_containers',
            'WHERE' => ['is_active' => 1],
        ]);
        foreach ($containers as $container) {
            $types = json_decode((string) ($container['itemtypes'] ?? ''), true);
            if (!is_array($types) || !in_array($itemtype, $types, true)) {
                continue;
            }
            $fields = $this->db->request([
                'SELECT' => ['name', 'label', 'type', 'is_readonly'],
                'FROM' => 'glpi_plugin_fields_fields',
                'WHERE' => ['plugin_fields_containers_id' => (int) $container['id'], 'is_active' => 1],
            ]);
            foreach ($fields as $field) {
                $label = trim((string) ($field['label'] ?? ''));
                if ($label === '' || (string) ($field['type'] ?? '') === 'header' || (int) ($field['is_readonly'] ?? 0) === 1) {
                    continue;
                }
                if (isset($result[$label])) {
                    throw new \RuntimeException(sprintf('Target Fields label "%s" is ambiguous for itemtype %s.', $label, $itemtype));
                }
                $result[$label] = [
                    'container_id' => (int) $container['id'],
                    'container_name' => (string) $container['name'],
                    'field_name' => (string) $field['name'],
                    'type' => (string) $field['type'],
                ];
            }
        }
        return $this->metadata[$itemtype] = $result;
    }

    /** @param array<string,mixed> $where @return array<string,mixed>|null */
    private function firstRow(string $table, array $where): ?array
    {
        $rows = $this->db->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1]);
        foreach ($rows as $row) {
            return $row;
        }
        return null;
    }

    /** @param array{container_id:int,container_name:string,field_name:string,type:string} $meta */
    private function assertGeneratedStorage(string $itemtype, array $meta): void
    {
        $class = \PluginFieldsContainer::getClassname($itemtype, $meta['container_name']);
        if (!class_exists($class) || !is_subclass_of($class, \CommonDBTM::class)) {
            throw new \RuntimeException('TARGET_FIELDS_CLASS_UNAVAILABLE: restore the Fields generated files before running this job.');
        }
        if (!$this->db->tableExists($class::getTable())) {
            throw new \RuntimeException('TARGET_FIELDS_TABLE_UNAVAILABLE: verify the Fields installation.');
        }
        if ($meta['type'] === 'dropdown') {
            if (!class_exists('PluginFieldsDropdown')) {
                throw new \RuntimeException('TARGET_FIELDS_DROPDOWN_CLASS_UNAVAILABLE: verify the Fields installation.');
            }
            $dropdown = \PluginFieldsDropdown::getClassname($meta['field_name']);
            if (!class_exists($dropdown) || !is_subclass_of($dropdown, \CommonDBTM::class)
                || !$this->db->tableExists($dropdown::getTable())) {
                throw new \RuntimeException('TARGET_FIELDS_DROPDOWN_CLASS_UNAVAILABLE: restore the Fields generated dropdown files.');
            }
        }
        $column = $this->columnName($meta);
        if (!$this->db->fieldExists($class::getTable(), $column)) {
            throw new \RuntimeException('TARGET_FIELDS_COLUMN_UNAVAILABLE: verify the Fields generated schema.');
        }
    }

    /** @param array{field_name:string,type:string} $meta */
    private function columnName(array $meta): string
    {
        if ($meta['type'] === 'dropdown') {
            return getForeignKeyFieldForItemType(\PluginFieldsDropdown::getClassname($meta['field_name']));
        }
        if (str_starts_with($meta['type'], 'dropdown-')) {
            // Fields already stores the native foreign-key prefix in name.
            return $meta['field_name'];
        }
        return $meta['field_name'];
    }

    /** @param array{field_name:string,type:string} $meta */
    private function normalizeFieldValue(mixed $value, array $meta): mixed
    {
        if (!is_scalar($value)) {
            throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\ProfileMigrationException('SOURCE_FIELDS_VALUE_INVALID');
        }
        $text = trim((string) $value);
        if ($meta['type'] === 'dropdown') {
            // Source numbers are display labels too, never target dropdown IDs.
            $class = \PluginFieldsDropdown::getClassname($meta['field_name']);
            $rows = $this->db->request(['FROM' => $class::getTable(), 'WHERE' => ['name' => $text], 'LIMIT' => 2]);
            $found = null;
            foreach ($rows as $row) {
                if ($found !== null) {
                    throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\ProfileMigrationException('TARGET_FIELDS_DROPDOWN_AMBIGUOUS');
                }
                $found = (int) $row['id'];
            }
            if ($found !== null) {
                return $found;
            }
            $dropdown = \GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\NativeItemFactory::create($class);
            $id = (int) $dropdown->add(['name' => $text]);
            if ($id <= 0) {
                throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\ProfileMigrationException('TARGET_FIELDS_DROPDOWN_CREATE_FAILED');
            }
            return $id;
        }
        if ($meta['type'] === 'date' || $meta['type'] === 'datetime') {
            $formats = $meta['type'] === 'date' ? ['d/m/Y', 'Y-m-d'] : ['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i'];
            foreach ($formats as $format) {
                $date = \DateTimeImmutable::createFromFormat('!' . $format, $text);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($date && ($errors === false || ($errors['error_count'] === 0 && $errors['warning_count'] === 0))) {
                    return $date->format($meta['type'] === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
                }
            }
            throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\ProfileMigrationException('SOURCE_DATE_INVALID');
        }
        if (in_array($meta['type'], ['number', 'integer', 'decimal'], true)) {
            $number = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' ', ','], ['', '', '', '.'], $text);
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $number)) {
                throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\ProfileMigrationException('SOURCE_NUMBER_INVALID');
            }
            return $number;
        }
        if (in_array($meta['type'], ['yesno', 'boolean'], true)) {
            return match (strtolower($text)) {
                '1', 'yes', 'oui', 'true' => 1,
                '0', 'no', 'non', 'false', '' => 0,
                default => throw new \GlpiPlugin\Uxconnector\Application\Sync\Exception\ProfileMigrationException('SOURCE_BOOLEAN_INVALID'),
            };
        }
        return $value;
    }
}
