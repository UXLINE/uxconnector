<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector;

/**
 * Central plugin lifecycle orchestrator.
 *
 * This class is infrastructure-only: it declares, installs, initializes
 * and validates the plugin. It never contains business logic, never
 * accesses a Repository directly and never triggers a Connector or a
 * synchronization (see RULE-SETUP-001 to RULE-SETUP-004).
 */
final class Setup
{
    /**
     * Plugin version exposed by this lifecycle class.
     */
    public const VERSION = '2.18.37';

    /**
     * Minimum GLPI version supported by this plugin.
     */
    private const GLPI_MIN_VERSION = '11.0.0';

    /**
     * First unsupported GLPI major version (exclusive).
     */
    private const GLPI_MAX_VERSION = '12.0.0';

    /**
     * Minimum PHP version required by this plugin.
     */
    private const PHP_MIN_VERSION = '8.2';

    /**
     * Returns the plugin metadata expected by GLPI's plugin_version_*().
     *
     * @return array<string, mixed>
     */
    public static function version(): array
    {
        return [
            'name'         => 'UXConnector',
            'version'      => self::VERSION,
            'author'       => 'HELPLINE',
            'license'      => 'GPL-3.0-or-later',
            'homepage'     => 'https://www.helpline.fr',
            'requirements' => [
                'glpi' => [
                    'min' => self::GLPI_MIN_VERSION,
                    'max' => self::GLPI_MAX_VERSION,
                ],
                'php' => [
                    'min' => self::PHP_MIN_VERSION,
                ],
            ],
        ];
    }

    /**
     * Registers the plugin components at load time.
     *
     * RULE-HOOKMANAGER-002 : Setup delegates immediately and entirely
     * to HookManager - it never registers a class, a menu, a right or
     * a CronTask itself.
     */
    public static function init(): void
    {
        Infrastructure\HookManager::register();
    }

    /**
     * Checks the technical prerequisites required to install/activate
     * the plugin.
     */
    public static function checkPrerequisites(): bool
    {
        return version_compare(PHP_VERSION, self::PHP_MIN_VERSION, '>=')
            && (!defined('GLPI_VERSION') || (
                version_compare(GLPI_VERSION, self::GLPI_MIN_VERSION, '>=')
                && version_compare(GLPI_VERSION, self::GLPI_MAX_VERSION, '<')
            ));
    }

    /**
     * Checks the plugin configuration validity.
     *
     * At this stage, the plugin has no persisted configuration yet:
     * the check is a no-op and always succeeds. It will delegate to the
     * Engine bounded context (AuthConfig / CredentialVault) once the
     * configuration storage is implemented.
     */
    public static function checkConfiguration(bool $verbose = false): bool
    {
        return true;
    }

    /**
     * Creates the database schema for a brand new installation.
     *
     * RULE-SETUP-001/002 : this method only orchestrates - the actual
     * DDL lives in versioned, idempotent scripts under sql/install/.
     * No Repository, no Connector, no synchronization is ever invoked
     * from here.
     */
    public static function install(\Migration $migration): bool
    {
        $installScript = require self::pluginRootDirectory() . '/sql/install/install-2.0.0.php';
        $installScript($migration);

        $migration->executeMigration();

        // ADR-10.10.02 : CronTask registration happens during
        // install/upgrade, never on every plugin_init() (HookManager
        // never touches CronTask - see its class doc-block).
        Infrastructure\CronRegistry::register();

        return true;
    }

    /**
     * Removes all plugin-owned persistence and GLPI integration metadata.
     *
     * Deactivation and upgrade preserve UXConnector data. An explicit GLPI
     * uninstall is destructive and removes the plugin schema, profile rights
     * and scheduled tasks so a later clean installation starts from a
     * deterministic state.
     */
    public static function uninstall(\Migration $migration): bool
    {
        Infrastructure\CronRegistry::unregister();

        $rights = array_map(
            static fn(array $definition): string => $definition['field'],
            \PluginUxconnectorProfile::getRightDefinitions(),
        );

        // Legacy right used by earlier UXConnector releases. It is no longer
        // part of getRightDefinitions(), but must still be cleaned on uninstall.
        $rights[] = 'plugin_uxconnector_connector';

        \ProfileRight::deleteProfileRights(
            array_values(array_unique($rights)),
        );

        // Drop dependent tables before their parent tables. Migration::dropTable()
        // is existence-safe, making uninstall deterministic and replayable.
        $tables = [
            'glpi_plugin_uxconnector_licenseassignments',
            'glpi_plugin_uxconnector_syncfailures',
            'glpi_plugin_uxconnector_objectlinks',
            'glpi_plugin_uxconnector_sync_checkpoints',
            'glpi_plugin_uxconnector_syncexecutions',
            'glpi_plugin_uxconnector_syncjobs',
            'glpi_plugin_uxconnector_authconfig_secrets',
            'glpi_plugin_uxconnector_semanticengineconfigs',
            'glpi_plugin_uxconnector_copilotclients',
            'glpi_plugin_uxconnector_connectors',
            'glpi_plugin_uxconnector_authconfigs',
        ];

        foreach ($tables as $table) {
            $migration->dropTable($table);
        }

        return true;
    }
    /**
     * Absolute path to the plugin's root directory (the one
     * containing composer.json, setup.php and sql/).
     */
    private static function pluginRootDirectory(): string
    {
        return dirname(__DIR__);
    }
}
