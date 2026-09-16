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
 * RULE-043 : ce fichier initialise GLPI, contrôle les droits, et
 * délègue immédiatement à un contrôleur. Aucun SQL, aucun Repository,
 * aucune Entité, aucun calcul métier ici (RULE-CTRL-001/002/003).
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\AuthConfigController;
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
    new OAuth2PasswordSecretComposer()
);

// Les actions du cycle d'activation modifient l'état d'une
// configuration existante : droit UPDATE requis, distinct du droit
// READ qui suffit pour la simple consultation de la liste.
if (isset($_POST['test'])) {
    Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
    $controller->test($_POST);
} else if (isset($_POST['activate'])) {
    Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
    $controller->activate($_POST);
} else if (isset($_POST['disable'])) {
    Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
    $controller->disable($_POST);
} else if (isset($_POST['delete'])) {
    Session::checkRight('plugin_uxconnector_authconfig', PURGE);
    $controller->delete($_POST);
} else {
    Html::header(
        __('Authentication configurations', 'uxconnector'),
        $_SERVER['PHP_SELF'],
        'tools',
        'PluginUxconnectorMenu',
        'authconfig'
    );
    $controller->index();
    Html::footer();
}
