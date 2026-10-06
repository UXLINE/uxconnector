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
 * PHPUnit bootstrap.
 *
 * Unit tests intentionally run without GLPI.
 * Integration tests MUST run against GLPI's dedicated testing environment,
 * never against the normal runtime database. When GLPI_ROOT is provided we
 * therefore delegate the whole GLPI boot sequence to GLPI's own
 * tests/bootstrap.php instead of trying to reproduce the Kernel/DB/session
 * initialization from the plugin.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$glpiRoot = getenv('GLPI_ROOT');

if ($glpiRoot === false || trim($glpiRoot) === '') {
    // Pure Unit mode: no GLPI dependency.
    require_once $pluginRoot . '/vendor/autoload.php';
    return;
}

$glpiRoot = rtrim(trim($glpiRoot), '/');
$coreTestBootstrap = $glpiRoot . '/tests/bootstrap.php';

if (!is_file($coreTestBootstrap)) {
    throw new RuntimeException(
        'UXConnector integration tests require GLPI\'s own tests/bootstrap.php. '
        . 'Use a GLPI source/development tree with a dedicated --env=testing database; '
        . 'do not run these integration tests against the normal GLPI runtime database. '
        . 'Missing file: ' . $coreTestBootstrap
    );
}

// GLPI's official test bootstrap initializes the testing Kernel, constants,
// config paths, database connection, cache, session/test fixtures and core
// autoloading in the supported order.
require_once $coreTestBootstrap;

// UXConnector's development dependencies/autoloading are plugin-local.
require_once $pluginRoot . '/vendor/autoload.php';

if (!class_exists(\Plugin::class)) {
    throw new RuntimeException('GLPI test bootstrap did not initialize the Plugin class.');
}

if (!\Plugin::isPluginActive('uxconnector')) {
    throw new RuntimeException(
        'UXConnector is not active in GLPI\'s testing environment. '
        . 'Install and enable it with bin/console plugin:install --env=testing '
        . 'and bin/console plugin:enable --env=testing before running Integration tests.'
    );
}
