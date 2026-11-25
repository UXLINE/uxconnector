<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Migration;

/** Read-only upgrade inventory: no names, credentials, mappings or source payloads are exported. */
final class LegacyJobScopeReport
{
    public function __construct(private readonly \DBmysql $db)
    {
    }

    /** @return array{creator_scope_assessment:string,active_jobs:list<array<string,mixed>>} */
    public function build(): array
    {
        $jobs = [];
        foreach ($this->db->request([
            'SELECT' => ['id', 'auth_config_reference', 'entity_assignment_strategy'],
            'FROM' => 'glpi_plugin_uxconnector_syncjobs',
            'WHERE' => ['status' => 'active'],
            'ORDER' => 'id ASC',
        ]) as $row) {
            $scope = self::scope($row['entity_assignment_strategy'] ?? null);
            $jobs[] = [
                'job_id' => (int) $row['id'],
                'connection_id' => (int) $row['auth_config_reference'],
                ...$scope,
            ];
        }
        return ['creator_scope_assessment' => 'unavailable: historical creator identity was not stored', 'active_jobs' => $jobs];
    }

    /** @return array{strategy:string,entity_ids:list<int>,scope_review_required:bool} */
    public static function scope(?string $json): array
    {
        $unresolved = ['strategy' => 'unconfigured', 'entity_ids' => [], 'scope_review_required' => true];
        if ($json === null || trim($json) === '') {
            return $unresolved;
        }
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return array_replace($unresolved, ['strategy' => 'invalid']);
        }
        if (!is_array($value)) {
            return array_replace($unresolved, ['strategy' => 'invalid']);
        }
        $type = $value['type'] ?? null;
        if ($type === 'mapped' && (
            !is_string($value['source_field'] ?? null) || trim($value['source_field']) === ''
            || !in_array($value['unknown_policy'] ?? 'fail', ['fail', 'use_default'], true)
            || (($value['unknown_policy'] ?? 'fail') === 'use_default' && !isset($value['default_entity_id']))
        )) {
            return array_replace($unresolved, ['strategy' => 'invalid']);
        }
        $ids = match ($type) {
            'fixed' => [$value['entity_id'] ?? null],
            'mapped' => is_array($value['mapping'] ?? null) ? array_values($value['mapping']) : [null],
            default => [null],
        };
        if ($type === 'mapped' && array_key_exists('default_entity_id', $value) && $value['default_entity_id'] !== null) {
            $ids[] = $value['default_entity_id'];
        }
        if ($ids === [] || array_filter($ids, static fn ($id): bool => !is_int($id) || $id < 0) !== []) {
            return array_replace($unresolved, ['strategy' => 'invalid']);
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return ['strategy' => (string) $type, 'entity_ids' => $ids, 'scope_review_required' => count($ids) !== 1];
    }
}
