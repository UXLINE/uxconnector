<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobPersistenceMapper;

return static function (Migration $migration): void {
    global $DB;

    $table = 'glpi_plugin_uxconnector_syncjobs';
    if (!$DB->fieldExists($table, 'webhook_key', false)) {
        return;
    }

    $vault = EngineServiceProvider::credentialVault();
    foreach ($DB->request(['SELECT' => ['id', 'webhook_key'], 'FROM' => $table]) as $row) {
        $key = strtolower(trim((string) $row['webhook_key']));
        if ($key === '') {
            continue;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new RuntimeException('Unable to migrate a webhook credential.');
        }
        $signingSecret = bin2hex(random_bytes(32));
        $protectedToken = $vault->protect($key);
        $protectedSigningSecret = $vault->protect($signingSecret);
        // Fail before removing legacy data if encryption cannot be read back with GLPI's current key.
        if (
            $protectedToken === $key || $protectedSigningSecret === $signingSecret
            || !hash_equals($key, $vault->reveal($protectedToken))
            || !hash_equals($signingSecret, $vault->reveal($protectedSigningSecret))
        ) {
            throw new RuntimeException('Webhook credential encryption verification failed.');
        }
        if (
            !$DB->update($table, [
                'webhook_key_hash' => SyncJobPersistenceMapper::webhookKeyHash($key),
                'webhook_token_secret' => $protectedToken,
                'webhook_signing_secret' => $protectedSigningSecret,
                'webhook_key' => null,
            ], ['id' => (int) $row['id']])
        ) {
            throw new RuntimeException('Unable to migrate a webhook credential.');
        }
    }

    foreach ($DB->request(['SELECT' => ['webhook_key'], 'FROM' => $table]) as $row) {
        if (trim((string) ($row['webhook_key'] ?? '')) !== '') {
            throw new RuntimeException('Legacy webhook credentials remain; migration must be retried.');
        }
    }

    $migration->dropField($table, 'webhook_key');
    $migration->executeMigration();
    if ($DB->fieldExists($table, 'webhook_key', false)) {
        throw new RuntimeException('Unable to remove the legacy webhook credential column.');
    }
};
