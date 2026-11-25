<?php

declare(strict_types=1);

/** Preserve replay reservations while bringing the primary key in line with GLPI conventions. */
return static function (Migration $migration): void {
    global $DB;

    $table = 'glpi_plugin_uxconnector_webhookrequests';
    if (!$DB->tableExists($table)) {
        throw new RuntimeException('Webhook replay table is missing.');
    }
    // Establish the unique reservation key BEFORE replacing the primary key, even on a resumed migration.
    $migration->addKey($table, 'fingerprint', 'fingerprint', 'UNIQUE');
    $migration->migrationOneTable($table);

    if (!$DB->fieldExists($table, 'id', false)) {
        $migration->dropKey($table, 'PRIMARY');
        // Both changes are buffered into one ALTER TABLE; existing fingerprints and expiry dates are kept.
        $migration->addField($table, 'id', 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', ['first' => true]);
        $migration->migrationOneTable($table);
    }
    if (!$DB->fieldExists($table, 'id', false)) {
        throw new RuntimeException('Webhook replay primary key migration did not complete.');
    }
};
