<?php

/** Removes legacy integration storage and revokes credentials from retired connection definitions. */

declare(strict_types=1);

return static function (Migration $migration): void {
    global $DB;

    $connections = 'glpi_plugin_uxconnector_authconfigs';
    $secrets = 'glpi_plugin_uxconnector_authconfig_secrets';
    $retired = ['connector_id' => 'openai-compatible'];

    $DB->beginTransaction();
    try {
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $connections, 'WHERE' => $retired]) as $connection) {
            if (!$DB->delete($secrets, ['plugin_uxconnector_authconfigs_id' => (int) $connection['id']])) {
                throw new RuntimeException('Unable to revoke a retired connection credential.');
            }
        }

        if (!$DB->update($connections, ['status' => 'disabled', 'connector_id' => 'custom'], $retired)) {
            throw new RuntimeException('Unable to retire legacy connections.');
        }
        $DB->commit();
    } catch (Throwable $exception) {
        $DB->rollback();
        throw $exception;
    }

    foreach (['glpi_plugin_uxconnector_semanticengineconfigs', 'glpi_plugin_uxconnector_copilotclients'] as $table) {
        $migration->dropTable($table);
        if ($DB->tableExists($table, false)) {
            throw new RuntimeException('Unable to remove legacy integration storage.');
        }
    }
};
