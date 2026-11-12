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

    $webhookRequestsTable = 'glpi_plugin_uxconnector_webhookrequests';
    if (!$DB->tableExists($webhookRequestsTable)) {
        $DB->runFile(__DIR__ . '/schema/webhookrequests.sql');
    }

    $authConfigTable = 'glpi_plugin_uxconnector_authconfigs';

    if (!$DB->tableExists($authConfigTable)) {
        $migration->displayMessage("Installing $authConfigTable");

        $DB->runFile(__DIR__ . '/schema/authconfigs.sql');
    }

    // 2.5.2 - AuthConfig connectivity tests now target a configurable
    // authenticated resource instead of assuming GET /. Existing rows use
    // "/" until an administrator recreates/updates the configuration with a
    // provider-specific test path.
    if ($DB->tableExists($authConfigTable) && !$DB->fieldExists($authConfigTable, 'test_path')) {
        $migration->addField(
            $authConfigTable,
            'test_path',
            'string',
            ['after' => 'endpoint', 'value' => '/'],
        );

        // Prior to 2.5.2, any HTTP response below 500 (including 401)
        // could leave an AuthConfig in TESTING. Force those legacy results
        // back to DRAFT so they cannot be activated without a strict 2xx
        // re-test after the upgrade.
        $DB->update($authConfigTable, ['status' => 'draft'], ['status' => 'testing']);
    }

    // RULE-VALUEOBJECT-003/CredentialVault design note : this table is
    // intentionally separate from `$authConfigTable`. It stores only
    // the opaque, already-GLPIKey-protected payload - never a
    // plaintext secret - and is deleted in cascade whenever its
    // parent AuthConfig is purged (see AuthConfigSecretStore::delete()
    // called from AuthConfigRepository::delete()).
    $secretTable = 'glpi_plugin_uxconnector_authconfig_secrets';

    if (!$DB->tableExists($secretTable)) {
        $migration->displayMessage("Installing $secretTable");

        $DB->runFile(__DIR__ . '/schema/authconfig_secrets.sql');
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

        $DB->runFile(__DIR__ . '/schema/syncjobs.sql');
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

        $DB->runFile(__DIR__ . '/schema/syncexecutions.sql');
    }

    // 2.18.4 - asynchronous execution supervision and resumable checkpoints.
    if ($DB->tableExists($syncExecutionTable)) {
        $executionFields = [
            'items_created' => ['integer', ['after' => 'items_processed', 'value' => 0]],
            'items_updated' => ['integer', ['after' => 'items_created', 'value' => 0]],
            'items_skipped' => ['integer', ['after' => 'items_updated', 'value' => 0]],
            'items_warnings' => ['integer', ['after' => 'items_skipped', 'value' => 0]],
            'phase' => ['string', ['after' => 'error_message', 'null' => true]],
            'checkpoint_cursor' => ['text', ['after' => 'phase', 'null' => true]],
            'checkpoint_page' => ['integer', ['after' => 'checkpoint_cursor', 'value' => 0]],
            'source_id' => ['string', ['after' => 'checkpoint_page', 'null' => true]],
            'last_success_source_id' => ['string', ['after' => 'source_id', 'null' => true]],
            'last_success_at' => ['datetime', ['after' => 'last_success_source_id', 'null' => true]],
            'heartbeat_at' => ['datetime', ['after' => 'last_success_at', 'null' => true]],
            'resume_available' => ['bool', ['after' => 'heartbeat_at', 'value' => 0]],
            'trigger_source' => ['string', ['after' => 'resume_available', 'value' => 'scheduled']],
            // 2.18.24: cooperative cancellation and exact next-page replay reference.
            'stop_requested' => ['bool', ['after' => 'trigger_source', 'value' => 0]],
            'resume_path' => ['text', ['after' => 'stop_requested', 'null' => true]],
        ];
        foreach ($executionFields as $field => [$type, $options]) {
            if (!$DB->fieldExists($syncExecutionTable, $field)) {
                $migration->addField($syncExecutionTable, $field, $type, $options);
            }
        }
        $migration->addKey($syncExecutionTable, ['status', 'started_at'], 'status_started_at');
    }

    // Per-item failures: enough information to diagnose/export/replay only failed records.
    $syncFailureTable = 'glpi_plugin_uxconnector_syncfailures';
    if (!$DB->tableExists($syncFailureTable)) {
        $migration->displayMessage("Installing $syncFailureTable");
        $DB->runFile(__DIR__ . '/schema/syncfailures.sql');
    }

    // 2.13.0 - opaque cursor checkpoints for incremental connectors
    // (Zendesk Incremental Ticket Export is the first bundled consumer).
    $syncCheckpointTable = 'glpi_plugin_uxconnector_sync_checkpoints';

    if (!$DB->tableExists($syncCheckpointTable)) {
        $migration->displayMessage("Installing $syncCheckpointTable");

        $DB->runFile(__DIR__ . '/schema/sync_checkpoints.sql');
    }

    // CORRECTIF (réconciliation, revue de code externe) : mémorise le
    // rapprochement entre un enregistrement externe et un objet GLPI,
    // pour ne plus jamais retomber sur une recherche ambiguë par
    // valeur métier (`match_field`) une fois ce rapprochement établi
    // une première fois - voir Domain\Sync\Entity\ObjectLink.
    $objectLinkTable = 'glpi_plugin_uxconnector_objectlinks';

    if (!$DB->tableExists($objectLinkTable)) {
        $migration->displayMessage("Installing $objectLinkTable");

        $DB->runFile(__DIR__ . '/schema/objectlinks.sql');
    }

    // RULE-MIGRATION additive : une instance déjà installée en
    // 2.x avant l'introduction d'ObjectLink porte une table
    // `syncjobs` sans la colonne `external_id_field` - l'ajouter
    // sans détruire les lignes existantes (Migration::addField()
    // est elle-même idempotente : elle ne réapplique rien si la
    // colonne existe déjà).
    if ($DB->tableExists($syncJobTable) && !$DB->fieldExists($syncJobTable, 'external_id_field')) {
        $migration->addField(
            $syncJobTable,
            'external_id_field',
            'string',
            ['after' => 'match_field', 'value' => ''],
        );
    }

    // RULE-MIGRATION additive : gestion des entités (§3.11 du document
    // de synthèse projet) - colonne nullable, aucune valeur par défaut
    // requise : NULL signifie explicitement "aucune stratégie
    // configurée", un comportement neutre pour toute instance
    // mono-entité et pour tout SyncJob existant avant ce correctif.
    if ($DB->tableExists($syncJobTable) && !$DB->fieldExists($syncJobTable, 'entity_assignment_strategy')) {
        $migration->addField(
            $syncJobTable,
            'entity_assignment_strategy',
            'text',
            ['after' => 'external_id_field', 'null' => true],
        );
    }

    // 2.7.0 - generic response shape and pagination are now persisted on
    // SyncJob. Existing jobs keep the historical behaviour deliberately:
    // collection at response root and no pagination until an administrator
    // configures them or recreates them from a catalogue template.
    if ($DB->tableExists($syncJobTable)) {
        if (!$DB->fieldExists($syncJobTable, 'collection_path')) {
            $migration->addField(
                $syncJobTable,
                'collection_path',
                'string',
                ['after' => 'external_id_field', 'value' => '$'],
            );
        }

        if (!$DB->fieldExists($syncJobTable, 'pagination_config')) {
            $migration->addField(
                $syncJobTable,
                'pagination_config',
                'text',
                ['after' => 'collection_path', 'null' => true],
            );
        }

        if (!$DB->fieldExists($syncJobTable, 'template_id')) {
            $migration->addField(
                $syncJobTable,
                'template_id',
                'string',
                ['after' => 'pagination_config', 'null' => true],
            );
        }

        if (!$DB->fieldExists($syncJobTable, 'template_version')) {
            $migration->addField(
                $syncJobTable,
                'template_version',
                'string',
                ['after' => 'template_id', 'null' => true],
            );
        }

        $migration->addKey($syncJobTable, 'template_id', 'template_id');
    }

    // 2.8.0 - a configured AuthConfig is now presented as a Connection.
    // connector_id associates that instance with an optional catalogue definition
    // (jira, intune, ...); custom keeps the fully manual REST use case available.
    if ($DB->tableExists($authConfigTable)) {
        if (!$DB->fieldExists($authConfigTable, 'connector_id')) {
            $migration->addField(
                $authConfigTable,
                'connector_id',
                'string',
                ['after' => 'test_path', 'value' => 'custom'],
            );
        }
        $migration->addKey($authConfigTable, 'connector_id', 'connector_id');
    }

    // 2.8.0 - Interaction runtime metadata. Polling remains the neutral default
    // for every existing SyncJob; outbound/webhook behavior is opt-in only.
    if ($DB->tableExists($syncJobTable)) {
        if (!$DB->fieldExists($syncJobTable, 'trigger_type')) {
            $migration->addField(
                $syncJobTable,
                'trigger_type',
                'string',
                ['after' => 'template_version', 'value' => 'polling'],
            );
        }
        if (!$DB->fieldExists($syncJobTable, 'external_object_type')) {
            $migration->addField(
                $syncJobTable,
                'external_object_type',
                'string',
                ['after' => 'trigger_type', 'value' => 'record'],
            );
        }
        if (!$DB->fieldExists($syncJobTable, 'outbound_config')) {
            $migration->addField(
                $syncJobTable,
                'outbound_config',
                'text',
                ['after' => 'external_object_type', 'null' => true],
            );
        }
        $migration->addField($syncJobTable, 'webhook_key_hash', 'char(64)', ['null' => true]);
        $migration->addField($syncJobTable, 'webhook_token_secret', 'text', ['null' => true]);
        $migration->addField($syncJobTable, 'webhook_signing_secret', 'text', ['null' => true]);
        $migration->addKey($syncJobTable, 'trigger_type', 'trigger_type');
        $migration->addKey($syncJobTable, 'webhook_key_hash', 'webhook_key_hash', 'UNIQUE');
    }

    // 2.8.0 - ObjectLink remains the single reconciliation concept, but lookups
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
                ['after' => 'external_id', 'value' => 'record'],
            );
        }
        $migration->addKey(
            $objectLinkTable,
            ['auth_config_reference', 'external_object_type', 'external_id'],
            'connection_external_lookup',
        );
        $migration->addKey(
            $objectLinkTable,
            ['auth_config_reference', 'itemtype', 'item_id'],
            'connection_item_lookup',
        );
    }

    // 2.5.1 - ObjectLink scope hardening. Older releases keyed an
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
                ['after' => 'auth_config_reference', 'value' => 0],
            );
        }

        $migration->dropKey($objectLinkTable, 'unicity');
        $migration->addKey(
            $objectLinkTable,
            ['auth_config_reference', 'itemtype', 'plugin_uxconnector_syncjobs_id', 'external_id'],
            'uniq_scope_external',
            'UNIQUE',
        );
        $migration->addKey(
            $objectLinkTable,
            'plugin_uxconnector_syncjobs_id',
            'plugin_uxconnector_syncjobs_id',
        );
    }

    // 2.17.1 - GLPI High-Level asset jobs created by 2.16.x used resource
    // paths without the `/api.php` prefix when the Connection endpoint was
    // stored as the tenant root URL. Migrate only the six bundled templates
    // and only when they still carry the legacy `/Assets/...` form.
    if ($DB->tableExists($syncJobTable)) {
        $legacyJobs = $DB->request([
            'SELECT' => ['id', 'resource_path', 'template_id'],
            'FROM' => $syncJobTable,
            'WHERE' => [
                'template_id' => [
                    'glpi-hl.assets.computers.inbound',
                    'glpi-hl.assets.monitors.inbound',
                    'glpi-hl.assets.phones.inbound',
                    'glpi-hl.assets.peripherals.inbound',
                    'glpi-hl.assets.printers.inbound',
                    'glpi-hl.assets.network-equipment.inbound',
                ],
                'resource_path' => ['LIKE', '/Assets/%'],
            ],
        ]);
        foreach ($legacyJobs as $job) {
            $DB->update(
                $syncJobTable,
                ['resource_path' => '/api.php' . $job['resource_path'], 'template_version' => '2.17.1'],
                ['id' => (int) $job['id'], 'resource_path' => $job['resource_path'],
                    'template_id' => $job['template_id']],
            );
        }
    }

    // 2.18.20 - individualized Microsoft licence assignments. This table is
    // additive and MUST survive plugin upgrades; only uninstall removes it.
    $licenseAssignmentTable = 'glpi_plugin_uxconnector_licenseassignments';
    if (!$DB->tableExists($licenseAssignmentTable)) {
        $migration->displayMessage("Installing $licenseAssignmentTable");
        $DB->runFile(__DIR__ . '/schema/licenseassignments.sql');
    }

    // Rights registration (RULE-MIGRATION-002 : Migration::addRight()
    // is itself idempotent - it never duplicates an existing grant on
    // re-run). PluginUxconnectorProfile::getRightDefinitions() is the
    // single source of truth for these right names; every
    // Session::checkRight()/haveRight() call in the plugin, and both
    // AuthConfigItem::$rightname/SyncJobItem::$rightname, must stay
    // in sync with it.
    foreach (\PluginUxconnectorProfile::getRightDefinitions() as $definition) {
        $defaultRights = isset($definition['rights']) ? 0 : ALLSTANDARDRIGHT;
        $migration->addRight($definition['field'], $defaultRights);
    }
};
