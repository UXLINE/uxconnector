<?php

/** UXConnector read-only connector catalogue entry point. */
declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Template\TemplateServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Template\Controller\ConnectorCatalogController;

Session::checkRight('plugin_uxconnector_syncjob', READ);

$controller = new ConnectorCatalogController(
    TemplateServiceProvider::listService(),
    TemplateServiceProvider::getService()
);

Html::header(
    __('Connector catalog', 'uxconnector'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginUxconnectorMenu',
    'catalog'
);

if (isset($_GET['connector']) && trim((string) $_GET['connector']) !== '') {
    $controller->show((string) $_GET['connector']);
} else {
    $controller->index();
}

Html::footer();
