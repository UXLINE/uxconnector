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

use Glpi\Http\SessionManager;
use GlpiPlugin\Uxconnector\Setup;

/**
 * IMPORTANT GLPI 11 lifecycle rule:
 * plugin_version_uxconnector() must remain self-contained because GLPI may
 * include setup.php to discover/check the plugin before registering the
 * plugin's PSR-4 autoloader.
 */
define('PLUGIN_UXCONNECTOR_VERSION', '2.17.1');
define('PLUGIN_UXCONNECTOR_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_UXCONNECTOR_MAX_GLPI_VERSION', '12.0.0');
define('PLUGIN_UXCONNECTOR_MIN_PHP_VERSION', '8.2');

/**
 * Plugin version declaration.
 *
 * @return array<string, mixed>
 */
function plugin_version_uxconnector(): array
{
    return [
        'name'         => 'UXConnector',
        'version'      => PLUGIN_UXCONNECTOR_VERSION,
        'author'       => 'HELPLINE',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://www.helpline.fr',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_UXCONNECTOR_MIN_GLPI_VERSION,
                'max' => PLUGIN_UXCONNECTOR_MAX_GLPI_VERSION,
            ],
            'php' => [
                'min' => PLUGIN_UXCONNECTOR_MIN_PHP_VERSION,
            ],
        ],
    ];
}

/**
 * Called by GLPI after the plugin PSR-4 autoloader has been registered.
 */
function plugin_init_uxconnector(): void
{
    Setup::init();
}

/** Register stateless machine-to-machine paths after GLPI bootstraps the plugin. */
function plugin_uxconnector_boot(): void
{
    SessionManager::registerPluginStatelessPath('uxconnector', '#^/front/(webhook|copilot|copilot-openapi|copilot-search|copilot-context|copilot-capabilities|copilot-ticket-summary|copilot-ticket-trends|copilot-categories|copilot-sla|copilot-sla-breaches|copilot-groups|copilot-technicians|copilot-compare|copilot-incidentology-global|copilot-incidentology-groups|copilot-incidentology-typologies|copilot-semantic-classification|copilot-semantic-prepare|copilot-semantic-finalize)\.php$#');
}

/**
 * Keep prerequisite checking independent from plugin classes so GLPI can
 * inspect the plugin safely in every lifecycle state.
 */
function plugin_uxconnector_check_prerequisites(): bool
{
    return version_compare(PHP_VERSION, PLUGIN_UXCONNECTOR_MIN_PHP_VERSION, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_UXCONNECTOR_MIN_GLPI_VERSION, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_UXCONNECTOR_MAX_GLPI_VERSION, '<');
}

/**
 * The current release has no mandatory persisted configuration.
 */
function plugin_uxconnector_check_config(bool $verbose = false): bool
{
    return true;
}

/**
 * Installs the plugin database schema.
 */
function plugin_uxconnector_install(): bool
{
    $migration = new Migration(PLUGIN_UXCONNECTOR_VERSION);

    return Setup::install($migration);
}

/**
 * Uninstalls the plugin database schema according to the plugin retention
 * policy implemented by Setup::uninstall().
 */
function plugin_uxconnector_uninstall(): bool
{
    $migration = new Migration(PLUGIN_UXCONNECTOR_VERSION);

    return Setup::uninstall($migration);
}
