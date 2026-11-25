<?php

declare(strict_types=1);

final class DatabaseDouble
{
    public array $rows = [];
    public array $credentials = [];
    public array $tables = [];
    public bool $failUpdate = false;
    public bool $failDrop = false;
    private array $snapshot = [];
    public function beginTransaction(): void
    {
        $this->snapshot = [$this->rows, $this->credentials];
    }
    public function commit(): void
    {
        $this->snapshot = [];
    }
    public function rollback(): void
    {
        [$this->rows, $this->credentials] = $this->snapshot;
    }
    public function request(array $query): iterable
    {
        check($query['FROM'] === 'glpi_plugin_uxconnector_authconfigs', 'unexpected query table');
        foreach ($this->rows as $row) {
            if ($row['connector_id'] === $query['WHERE']['connector_id']) {
                yield ['id' => $row['id']];
            }
        }
    }
    public function delete(string $table, array $where): bool
    {
        check($table === 'glpi_plugin_uxconnector_authconfig_secrets', 'unexpected delete table');
        unset($this->credentials[$where['plugin_uxconnector_authconfigs_id']]);
        return true;
    }
    public function update(string $table, array $values, array $where): bool
    {
        check($table === 'glpi_plugin_uxconnector_authconfigs', 'unexpected update table');
        if ($this->failUpdate) {
            return false;
        }
        foreach ($this->rows as &$row) {
            if ($row['connector_id'] === $where['connector_id']) {
                $row = array_replace($row, $values);
            }
        }
        return true;
    }
    public function tableExists(string $table, bool $cache = true): bool
    {
        check(!$cache, 'post-drop check must bypass cache');
        return isset($this->tables[$table]);
    }
}
final class Migration
{
    public function dropTable(string $table): void
    {
        global $DB;
        if (!$DB->failDrop) {
            unset($DB->tables[$table]);
        }
    }
}
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new LogicException($message);
    }
}
$retire = require $argv[1] . '/sql/install/retire-integrations.php';
$DB = new DatabaseDouble();
$migration = new Migration();
$retire($migration);
check($DB->rows === [] && $DB->tables === [], 'fresh install');
$DB->rows = [
    10 => ['id' => 10, 'connector_id' => 'openai-compatible', 'status' => 'active'],
    11 => ['id' => 11, 'connector_id' => 'glpi-hl', 'status' => 'active'],
    12 => ['id' => 12, 'connector_id' => 'openai-compatible', 'status' => 'disabled'],
    13 => ['id' => 13, 'connector_id' => 'custom', 'status' => 'active'],
];
$DB->credentials = [10 => 'placeholder-10', 11 => 'placeholder-11', 13 => 'placeholder-13'];
$DB->tables = array_fill_keys([
    'glpi_plugin_uxconnector_semanticengineconfigs', 'glpi_plugin_uxconnector_copilotclients',
    'glpi_plugin_uxconnector_syncjobs', 'glpi_plugin_uxconnector_syncexecutions',
], true);
$before = [$DB->rows, $DB->credentials, $DB->tables];
$DB->failUpdate = true;
try {
    $retire($migration);
    throw new LogicException('rollback was not triggered');
} catch (RuntimeException $e) {
    check($before === [$DB->rows, $DB->credentials, $DB->tables], 'partial migration was not rolled back');
}
$DB->failUpdate = false;
$DB->failDrop = true;
try {
    $retire($migration);
    throw new LogicException('drop failure went unnoticed');
} catch (RuntimeException $e) {
    check(isset($DB->tables['glpi_plugin_uxconnector_copilotclients']), 'invalid fixture');
}
$DB->failDrop = false;
$retire($migration);
check($DB->rows[10]['status'] === 'disabled' && $DB->rows[10]['connector_id'] === 'custom', 'active retired connection');
check($DB->rows[12]['status'] === 'disabled' && $DB->rows[12]['connector_id'] === 'custom', 'disabled retired connection');
check($DB->rows[11] === $before[0][11] && $DB->rows[13] === $before[0][13], 'legitimate connection changed');
check($DB->credentials === [11 => 'placeholder-11', 13 => 'placeholder-13'], 'credential scope');
check(array_keys($DB->tables) === ['glpi_plugin_uxconnector_syncjobs', 'glpi_plugin_uxconnector_syncexecutions'], 'table scope');
$after = [$DB->rows, $DB->credentials, $DB->tables];
$retire($migration);
check($after === [$DB->rows, $DB->credentials, $DB->tables], 'idempotence');
echo "OK fresh install, scoped retirement, rollback, DDL retry, replay\n";
