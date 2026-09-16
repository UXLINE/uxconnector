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

Session::checkRight('plugin_uxconnector_authconfig', CREATE);

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

if (isset($_POST['add'])) {
    // POST handlers may redirect. Do not emit the GLPI page header before
    // the controller has handled the request.
    $controller->create($_POST);
} else {
    Html::header(
        __('New authentication configuration', 'uxconnector'),
        $_SERVER['PHP_SELF'],
        'tools',
        'PluginUxconnectorMenu',
        'authconfig'
    );
    $controller->showCreateForm($_GET);
    Html::footer();
}
