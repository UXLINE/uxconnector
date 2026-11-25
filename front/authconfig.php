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
 * RULE-043: GLPI bootstraps this route. It checks rights and delegates
 * immediately to a controller. No SQL, repository or domain logic belongs
 * in this entry point (RULE-CTRL-001/002/003).
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\AuthConfigController;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\GlpiRestV1SecretComposer;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\OAuth2ClientCredentialsSecretComposer;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\OAuth2PasswordSecretComposer;

Session::checkRight('plugin_uxconnector_authconfig', READ);

$controller = new AuthConfigController(
    EngineServiceProvider::listAuthConfigsService(),
    EngineServiceProvider::createAuthConfigService(),
    EngineServiceProvider::testAuthConfigService(),
    EngineServiceProvider::activateAuthConfigService(),
    EngineServiceProvider::disableAuthConfigService(),
    EngineServiceProvider::deleteAuthConfigService(),
    new OAuth2ClientCredentialsSecretComposer(),
    new OAuth2PasswordSecretComposer(),
    new GlpiRestV1SecretComposer(),
    SyncServiceProvider::jobEntityAccess(),
);

// Connection lifecycle actions require UPDATE; list consultation only requires READ.
if (isset($_POST['test'])) {
    Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
    $controller->test($_POST);
} elseif (isset($_POST['activate'])) {
    Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
    $controller->activate($_POST);
} elseif (isset($_POST['disable'])) {
    Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
    $controller->disable($_POST);
} elseif (isset($_POST['delete'])) {
    Session::checkRight('plugin_uxconnector_authconfig', PURGE);
    $controller->delete($_POST);
} else {
    Html::header(
        __('Authentication configurations', 'uxconnector'),
        $_SERVER['PHP_SELF'],
        'tools',
        'PluginUxconnectorMenu',
        'authconfig',
    );
    $controller->index();
    Html::footer();
}
