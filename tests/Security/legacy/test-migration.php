<?php

declare(strict_types=1);

const AUTH_CONFIGS = 'glpi_plugin_uxconnector_authconfigs';
const SYNC_JOBS = 'glpi_plugin_uxconnector_syncjobs';
const ASSET_TEMPLATE = 'glpi-hl.assets.computers.inbound';

final class Migration
{
    public function addField(string $table, string $field, mixed ...$options): void
    {
        $GLOBALS['DB']->fields[$table][$field] = true;
    }

    public function addKey(mixed ...$arguments): void
    {
    }

    public function dropKey(mixed ...$arguments): void
    {
    }
}

final class PluginUxconnectorProfile
{
    public static function getRightDefinitions(): array
    {
        return [];
    }
}

final class DatabaseDouble
{
    public array $fields = [];
    public array $tables = [
        AUTH_CONFIGS => [
            1 => ['status' => 'testing'],
            2 => ['status' => 'active'],
        ],
        SYNC_JOBS => [
            1 => ['id' => 1, 'resource_path' => '/Assets/Computer', 'template_id' => ASSET_TEMPLATE],
            2 => ['id' => 2, 'resource_path' => '/api.php/Assets/Computer', 'template_id' => ASSET_TEMPLATE],
            3 => ['id' => 3, 'resource_path' => '/Assets/Computer', 'template_id' => 'custom'],
            4 => ['id' => 4, 'resource_path' => '/Other', 'template_id' => ASSET_TEMPLATE],
            5 => ['id' => 5, 'resource_path' => '/Assets/Computer', 'template_id' => ASSET_TEMPLATE],
        ],
    ];

    public function tableExists(string $table): bool
    {
        return true;
    }

    public function fieldExists(string $table, string $field): bool
    {
        return [$table, $field] !== [AUTH_CONFIGS, 'test_path'] || isset($this->fields[$table][$field]);
    }

    public function request(array $criteria): array
    {
        if ($criteria['FROM'] !== SYNC_JOBS || $criteria['WHERE']['resource_path'] !== ['LIKE', '/Assets/%']) {
            throw new LogicException('Unexpected criteria for legacy jobs.');
        }
        $rows = array_filter(
            $this->tables[SYNC_JOBS],
            static fn (array $job): bool =>
            in_array($job['template_id'], $criteria['WHERE']['template_id'], true)
            && str_starts_with($job['resource_path'], '/Assets/')
        );
        // Simulate a path edit after the SELECT and before the updates.
        $this->tables[SYNC_JOBS][5]['resource_path'] = '/Edited';
        return $rows;
    }

    public function update(string $table, array $values, array $where): bool
    {
        foreach ($this->tables[$table] as &$row) {
            if (array_intersect_assoc($where, $row) === $where) {
                $row = array_replace($row, $values);
            }
        }
        return true;
    }
}

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$DB = new DatabaseDouble();
$migrate = require $argv[1] . '/sql/install/install-2.0.0.php';
$migrate(new Migration());
verify($DB->tables[AUTH_CONFIGS] === [1 => ['status' => 'draft'], 2 => ['status' => 'active']], 'Wrong status migration scope.');
$jobs = $DB->tables[SYNC_JOBS];
verify($jobs[1]['resource_path'] === '/api.php/Assets/Computer' && $jobs[1]['template_version'] === '2.17.1', 'Legacy job was not migrated.');
verify(array_filter(array_slice($jobs, 1), static fn (array $job): bool => isset($job['template_version'])) === [], 'Unrelated job changed.');
verify($jobs[2]['resource_path'] === '/api.php/Assets/Computer' && $jobs[3]['resource_path'] === '/Assets/Computer' && $jobs[4]['resource_path'] === '/Other' && $jobs[5]['resource_path'] === '/Edited', 'Path scope or concurrent edit not preserved.');
$state = $DB->tables;
$migrate(new Migration());
verify($state === $DB->tables, 'Migration is not idempotent.');
echo "OK: legacy statuses, scoped asset paths, concurrent edit, idempotent migration.\n";
