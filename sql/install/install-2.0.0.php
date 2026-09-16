<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * Baseline schema introduced in version 2.0.0 and replayed by the
 * plugin install/update lifecycle. Newer additive migrations live at
 * the end of this script so a fresh install and an upgrade converge
 * to the same schema.
 *
 * RULE-MIGRATION-002 : idempotent (table/field/index operations are
 * guarded or use GLPI Migration helpers).
 * RULE-MIGRATION-003 : existing business rows are preserved.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * Creates every table owned by the Engine bounded context.
 *
 * Kept as a plain function (not a class) so it can be required and
 * invoked directly by Setup::install(), which already receives the
 * Migration instance created by plugin_uxconnector_install().
 */
return static function (Migration $migration): void {
    global $DB;

    $authConfigTable = 'glpi_plugin_uxconnector_authconfigs';

    if (!$DB->tableExists($authConfigTable)) {
        $migration->displayMessage("Installing $authConfigTable");

        $query = <<<SQL
            CREATE TABLE `$authConfigTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(128) NOT NULL,
                `endpoint` varchar(255) NOT NULL,
                `test_path` varchar(255) NOT NULL DEFAULT '/',
                `connector_id` varchar(64) NOT NULL DEFAULT 'custom',
                `auth_method` varchar(64) NOT NULL,
                `status` varchar(32) NOT NULL DEFAULT 'draft',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `connector_id` (`connector_id`),
                KEY `status` (`status`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
            SQL;

        $DB->doQuery($query) || throw new \RuntimeException(
            "Failed to create table $authConfigTable: " . $DB->error()
        );
    }

    // 2.5.2 — AuthConfig connectivity tests now target a configurable
    // authenticated resource instead of assuming GET /. Existing rows use
    // "/" until an administrator recreates/updates the configuration with a
    // provider-specific test path.
    if ($DB->tableExists($authConfigTable) && !$DB->fieldExists($authConfigTable, 'test_path')) {
        $migration->addField(
            $authConfigTable,
            'test_path',
            'string',
            ['after' => 'endpoint', 'value' => '/']
        );

        // Prior to 2.5.2, any HTTP response below 500 (including 401)
        // could leave an AuthConfig in TESTING. Force those legacy results
        // back to DRAFT so they cannot be activated without a strict 2xx
        // re-test after the upgrade.
        $DB->doQuery(
            "UPDATE `$authConfigTable` SET `status` = 'draft' WHERE `status` = 'testing'"
        ) || throw new \RuntimeException(
            'Failed to reset legacy AuthConfig testing statuses: ' . $DB->error()
        );
    }

    // RULE-VALUEOBJECT-003/CredentialVault design note : this table is
    // intentionally separate from `$authConfigTable`. It stores only
    // the opaque, already-GLPIKey-protected payload — never a
    // plaintext secret — and is deleted in cascade whenever its
    // parent AuthConfig is purged (see AuthConfigSecretStore::delete()
    // called from AuthConfigRepository::delete()).
    $secretTable = 'glpi_plugin_uxconnector_authconfig_secrets';

    if (!$DB->tableExists($secretTable)) {
        $migration->displayMessage("Installing $secretTable");

        $query = <<<SQL
            CREATE TABLE `$secretTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_uxconnector_authconfigs_id` int unsigned NOT NULL,
                `protected_secret` text NOT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_uxconnector_authconfigs_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
            SQL;

        $DB->doQuery($query) || throw new \RuntimeException(
            "Failed to create table $secretTable: " . $DB->error()
        );
    }

    // Sync bounded context. `auth_config_reference` intentionally has
    // no SQL foreign key towards `$authConfigTable`: the two tables
    // belong to different bounded contexts (see
    // Domain\Sync\ValueObject\AuthConfigReference's doc-block), and a
    // cross-context FK would couple their schemas' evolution at the
    // database level even though the code deliberately does not.
    // Referential integrity between the two is enforced at the
    // Application layer instead (a future
    // EnsureAuthConfigExistsService/validation on SyncJob creation).
    $syncJobTable = 'glpi_plugin_uxconnector_syncjobs';

    if (!$DB->tableExists($syncJobTable)) {
        $migration->displayMessage("Installing $syncJobTable");

        $query = <<<SQL
            CREATE TABLE `$syncJobTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(128) NOT NULL,
                `auth_config_reference` int unsigned NOT NULL,
                `direction` varchar(32) NOT NULL,
                `resource_path` varchar(255) NOT NULL,
                `target_itemtype` varchar(32) NOT NULL,
                `match_field` varchar(128) NOT NULL,
                `external_id_field` varchar(128) NOT NULL,
                `collection_path` varchar(255) NOT NULL DEFAULT '$',
                `pagination_config` text NULL,
                `template_id` varchar(191) NULL,
                `template_version` varchar(32) NULL,
                `trigger_type` varchar(32) NOT NULL DEFAULT 'polling',
                `external_object_type` varchar(64) NOT NULL DEFAULT 'record',
                `outbound_config` text NULL,
                `webhook_key` varchar(64) NULL,
                `entity_assignment_strategy` text NULL,
                `field_mappings` text NOT NULL,
                `status` varchar(32) NOT NULL DEFAULT 'draft',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `auth_config_reference` (`auth_config_reference`),
                KEY `template_id` (`template_id`),
                UNIQUE KEY `webhook_key` (`webhook_key`),
                KEY `trigger_type` (`trigger_type`),
                KEY `status` (`status`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
            SQL;

        $DB->doQuery($query) || throw new \RuntimeException(
            "Failed to create table $syncJobTable: " . $DB->error()
        );
    }

    // Deliberately no FK from syncexecutions to syncjobs either
    // (RULE-032, adapted) : SyncExecution is purged by a future
    // retention policy on its own schedule. UXConnector also purges
    // this history explicitly when an Interaction is deleted; a hard FK would force cascade
    // semantics the Domain does not want (see SyncExecution's
    // class doc-block).
    $syncExecutionTable = 'glpi_plugin_uxconnector_syncexecutions';

    if (!$DB->tableExists($syncExecutionTable)) {
        $migration->displayMessage("Installing $syncExecutionTable");

        $query = <<<SQL
            CREATE TABLE `$syncExecutionTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_uxconnector_syncjobs_id` int unsigned NOT NULL,
                `status` varchar(32) NOT NULL,
                `started_at` timestamp NULL DEFAULT NULL,
                `finished_at` timestamp NULL DEFAULT NULL,
                `items_processed` int unsigned NOT NULL DEFAULT 0,
                `items_failed` int unsigned NOT NULL DEFAULT 0,
                `error_message` text NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_uxconnector_syncjobs_id` (`plugin_uxconnector_syncjobs_id`),
                KEY `status` (`status`),
                KEY `started_at` (`started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
            SQL;

        $DB->doQuery($query) || throw new \RuntimeException(
            "Failed to create table $syncExecutionTable: " . $DB->error()
        );
    }

    // 2.13.0 — opaque cursor checkpoints for incremental connectors
    // (Zendesk Incremental Ticket Export is the first bundled consumer).
    $syncCheckpointTable = 'glpi_plugin_uxconnector_sync_checkpoints';

    if (!$DB->tableExists($syncCheckpointTable)) {
        $migration->displayMessage("Installing $syncCheckpointTable");

        $query = <<<SQL
            CREATE TABLE `$syncCheckpointTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_uxconnector_syncjobs_id` int unsigned NOT NULL,
                `cursor_value` text NOT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `syncjob` (`plugin_uxconnector_syncjobs_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
            SQL;

        $DB->doQuery($query) || throw new \RuntimeException(
            "Failed to create table $syncCheckpointTable: " . $DB->error()
        );
    }

    // CORRECTIF (réconciliation, revue de code externe) : mémorise le
    // rapprochement entre un enregistrement externe et un objet GLPI,
    // pour ne plus jamais retomber sur une recherche ambiguë par
    // valeur métier (`match_field`) une fois ce rapprochement établi
    // une première fois — voir Domain\Sync\Entity\ObjectLink.
    $objectLinkTable = 'glpi_plugin_uxconnector_objectlinks';

    if (!$DB->tableExists($objectLinkTable)) {
        $migration->displayMessage("Installing $objectLinkTable");

        $query = <<<SQL
            CREATE TABLE `$objectLinkTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `auth_config_reference` int unsigned NOT NULL,
                `plugin_uxconnector_syncjobs_id` int unsigned NOT NULL DEFAULT 0,
                `external_id` varchar(191) NOT NULL,
                `external_object_type` varchar(64) NOT NULL DEFAULT 'record',
                `itemtype` varchar(32) NOT NULL,
                `item_id` int unsigned NOT NULL,
                `last_seen_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_scope_external` (`auth_config_reference`, `itemtype`, `plugin_uxconnector_syncjobs_id`, `external_id`),
                KEY `plugin_uxconnector_syncjobs_id` (`plugin_uxconnector_syncjobs_id`),
                KEY `connection_external_lookup` (`auth_config_reference`, `external_object_type`, `external_id`),
                KEY `connection_item_lookup` (`auth_config_reference`, `itemtype`, `item_id`),
                KEY `item_lookup` (`itemtype`, `item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
            SQL;

        $DB->doQuery($query) || throw new \RuntimeException(
            "Failed to create table $objectLinkTable: " . $DB->error()
        );
    }

    // 2.9.0 — Copilot Gateway API clients. Secrets are never persisted: only a SHA-256 hash of the generated API key is stored.
    $copilotClientTable = 'glpi_plugin_uxconnector_copilotclients';
    if (!$DB->tableExists($copilotClientTable)) {
        $migration->displayMessage("Installing $copilotClientTable");
        $query = <<<SQL
            CREATE TABLE `$copilotClientTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(128) NOT NULL,
                `api_key_hash` char(64) NOT NULL,
                `provider_type` varchar(32) NOT NULL DEFAULT 'glpi_hl_api',
                `auth_config_reference` int unsigned NOT NULL,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `api_key_hash` (`api_key_hash`),
                KEY `auth_config_reference` (`auth_config_reference`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
            SQL;
        $DB->doQuery($query) || throw new \RuntimeException(
            "Failed to create table $copilotClientTable: " . $DB->error()
        );
    }

    // 2.9.1 — identify whether an AI client reads the hosting GLPI directly
    // or delegates to a configured remote GLPI High-Level API connection.
    if ($DB->tableExists($copilotClientTable) && !$DB->fieldExists($copilotClientTable, 'provider_type')) {
        $migration->addField(
            $copilotClientTable,
            'provider_type',
            'string',
            ['after' => 'api_key_hash', 'value' => 'glpi_hl_api']
        );
    }

    // RULE-MIGRATION additive : une instance déjà installée en
    // 2.x avant l'introduction d'ObjectLink porte une table
    // `syncjobs` sans la colonne `external_id_field` — l'ajouter
    // sans détruire les lignes existantes (Migration::addField()
    // est elle-même idempotente : elle ne réapplique rien si la
    // colonne existe déjà).
    if ($DB->tableExists($syncJobTable) && !$DB->fieldExists($syncJobTable, 'external_id_field')) {
        $migration->addField(
            $syncJobTable,
            'external_id_field',
            'string',
            ['after' => 'match_field', 'value' => '']
        );
    }

    // RULE-MIGRATION additive : gestion des entités (§3.11 du document
    // de synthèse projet) — colonne nullable, aucune valeur par défaut
    // requise : NULL signifie explicitement "aucune stratégie
    // configurée", un comportement neutre pour toute instance
    // mono-entité et pour tout SyncJob existant avant ce correctif.
    if ($DB->tableExists($syncJobTable) && !$DB->fieldExists($syncJobTable, 'entity_assignment_strategy')) {
        $migration->addField(
            $syncJobTable,
            'entity_assignment_strategy',
            'text',
            ['after' => 'external_id_field', 'null' => true]
        );
    }

    // 2.7.0 — generic response shape and pagination are now persisted on
    // SyncJob. Existing jobs keep the historical behaviour deliberately:
    // collection at response root and no pagination until an administrator
    // configures them or recreates them from a catalogue template.
    if ($DB->tableExists($syncJobTable)) {
        if (!$DB->fieldExists($syncJobTable, 'collection_path')) {
            $migration->addField(
                $syncJobTable,
                'collection_path',
                'string',
                ['after' => 'external_id_field', 'value' => '$']
            );
        }

        if (!$DB->fieldExists($syncJobTable, 'pagination_config')) {
            $migration->addField(
                $syncJobTable,
                'pagination_config',
                'text',
                ['after' => 'collection_path', 'null' => true]
            );
        }

        if (!$DB->fieldExists($syncJobTable, 'template_id')) {
            $migration->addField(
                $syncJobTable,
                'template_id',
                'string',
                ['after' => 'pagination_config', 'null' => true]
            );
        }

        if (!$DB->fieldExists($syncJobTable, 'template_version')) {
            $migration->addField(
                $syncJobTable,
                'template_version',
                'string',
                ['after' => 'template_id', 'null' => true]
            );
        }

        $migration->addKey($syncJobTable, 'template_id', 'template_id');
    }

    // 2.8.0 — a configured AuthConfig is now presented as a Connection.
    // connector_id associates that instance with an optional catalogue definition
    // (jira, intune, ...); custom keeps the fully manual REST use case available.
    if ($DB->tableExists($authConfigTable)) {
        if (!$DB->fieldExists($authConfigTable, 'connector_id')) {
            $migration->addField(
                $authConfigTable,
                'connector_id',
                'string',
                ['after' => 'test_path', 'value' => 'custom']
            );
        }
        $migration->addKey($authConfigTable, 'connector_id', 'connector_id');
    }

    // 2.8.0 — Interaction runtime metadata. Polling remains the neutral default
    // for every existing SyncJob; outbound/webhook behavior is opt-in only.
    if ($DB->tableExists($syncJobTable)) {
        if (!$DB->fieldExists($syncJobTable, 'trigger_type')) {
            $migration->addField(
                $syncJobTable,
                'trigger_type',
                'string',
                ['after' => 'template_version', 'value' => 'polling']
            );
        }
        if (!$DB->fieldExists($syncJobTable, 'external_object_type')) {
            $migration->addField(
                $syncJobTable,
                'external_object_type',
                'string',
                ['after' => 'trigger_type', 'value' => 'record']
            );
        }
        if (!$DB->fieldExists($syncJobTable, 'outbound_config')) {
            $migration->addField(
                $syncJobTable,
                'outbound_config',
                'text',
                ['after' => 'external_object_type', 'null' => true]
            );
        }
        if (!$DB->fieldExists($syncJobTable, 'webhook_key')) {
            $migration->addField(
                $syncJobTable,
                'webhook_key',
                'string',
                ['after' => 'outbound_config', 'null' => true]
            );
        }
        $migration->addKey($syncJobTable, 'trigger_type', 'trigger_type');
        $migration->addKey($syncJobTable, 'webhook_key', 'webhook_key', 'UNIQUE');
    }

    // 2.8.0 — ObjectLink remains the single reconciliation concept, but lookups
    // can now cross two interactions belonging to the same Connection. The old
    // per-job unique key is deliberately retained for safe upgrades; the new
    // connection-level indexes support cross-job resolution without deleting or
    // rewriting historical links.
    if ($DB->tableExists($objectLinkTable)) {
        if (!$DB->fieldExists($objectLinkTable, 'external_object_type')) {
            $migration->addField(
                $objectLinkTable,
                'external_object_type',
                'string',
                ['after' => 'external_id', 'value' => 'record']
            );
        }
        $migration->addKey(
            $objectLinkTable,
            ['auth_config_reference', 'external_object_type', 'external_id'],
            'connection_external_lookup'
        );
        $migration->addKey(
            $objectLinkTable,
            ['auth_config_reference', 'itemtype', 'item_id'],
            'connection_item_lookup'
        );
    }

    // 2.5.1 — ObjectLink scope hardening. Older releases keyed an
    // external record only by AuthConfig + ExternalId. Two SyncJobs using the
    // same connector could therefore collide on the same external identifier.
    // Existing links are retained with syncjobs_id = 0 (legacy/inert); new
    // executions always write a positive SyncJob id and never match those
    // legacy rows accidentally.
    if ($DB->tableExists($objectLinkTable)) {
        if (!$DB->fieldExists($objectLinkTable, 'plugin_uxconnector_syncjobs_id')) {
            $migration->addField(
                $objectLinkTable,
                'plugin_uxconnector_syncjobs_id',
                'fkey',
                ['after' => 'auth_config_reference', 'value' => 0]
            );
        }

        $migration->dropKey($objectLinkTable, 'unicity');
        $migration->addKey(
            $objectLinkTable,
            ['auth_config_reference', 'itemtype', 'plugin_uxconnector_syncjobs_id', 'external_id'],
            'uniq_scope_external',
            'UNIQUE'
        );
        $migration->addKey(
            $objectLinkTable,
            'plugin_uxconnector_syncjobs_id',
            'plugin_uxconnector_syncjobs_id'
        );
    }

    // 2.12.0 — semantic classification engine configuration.
    // Endpoint/authentication/credential remain owned by Engine/AuthConfig.
    // `auth_config_reference` is deliberately an application reference only:
    // no cross-bounded-context SQL foreign key is introduced.
    $semanticEngineConfigTable = 'glpi_plugin_uxconnector_semanticengineconfigs';

    if (!$DB->tableExists($semanticEngineConfigTable)) {
        $migration->displayMessage("Installing $semanticEngineConfigTable");
        $query = <<<SQL
            CREATE TABLE `$semanticEngineConfigTable` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(128) NOT NULL,
                `auth_config_reference` int unsigned NOT NULL,
                `model` varchar(191) NOT NULL,
                `inference_path` varchar(255) NOT NULL,
                `batch_size` int unsigned NOT NULL DEFAULT 20,
                `confidence_threshold` decimal(5,4) NOT NULL DEFAULT 0.7000,
                `temperature` decimal(5,4) NOT NULL DEFAULT 0.0000,
                `classifier_version` varchar(32) NOT NULL DEFAULT '1.0.0',
                `status` varchar(32) NOT NULL DEFAULT 'draft',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `auth_config_reference` (`auth_config_reference`),
                KEY `status` (`status`),
                KEY `classifier_version` (`classifier_version`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
            SQL;

        $DB->doQuery($query) || throw new \RuntimeException(
            "Failed to create table $semanticEngineConfigTable: " . $DB->error()
        );
    }
    // 2.17.1 — GLPI High-Level asset jobs created by 2.16.x used resource
    // paths without the `/api.php` prefix when the Connection endpoint was
    // stored as the tenant root URL. Migrate only the six bundled templates
    // and only when they still carry the legacy `/Assets/...` form.
    if ($DB->tableExists($syncJobTable)) {
        $query = <<<SQL
            UPDATE `$syncJobTable`
            SET `resource_path` = CONCAT('/api.php', `resource_path`),
                `template_version` = '2.17.1'
            WHERE `template_id` IN (
                'glpi-hl.assets.computers.inbound',
                'glpi-hl.assets.monitors.inbound',
                'glpi-hl.assets.phones.inbound',
                'glpi-hl.assets.peripherals.inbound',
                'glpi-hl.assets.printers.inbound',
                'glpi-hl.assets.network-equipment.inbound'
            )
              AND `resource_path` LIKE '/Assets/%';
            SQL;
        $DB->doQuery($query) || throw new \RuntimeException(
            'Failed to migrate GLPI High-Level asset resource paths: ' . $DB->error()
        );
    }

    // Rights registration (RULE-MIGRATION-002 : Migration::addRight()
    // is itself idempotent — it never duplicates an existing grant on
    // re-run). PluginUxconnectorProfile::getRightDefinitions() is the
    // single source of truth for these two right names; every
    // Session::checkRight()/haveRight() call in the plugin, and both
    // AuthConfigItem::$rightname/SyncJobItem::$rightname, must stay
    // in sync with it.
    foreach (\PluginUxconnectorProfile::getRightDefinitions() as $definition) {
        $migration->addRight($definition['field'], ALLSTANDARDRIGHT);
    }
};
