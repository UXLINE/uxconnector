<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\SyncJobEditController;

Session::checkRight(
    'plugin_uxconnector_syncjob',
    UPDATE,
);

$controller = new SyncJobEditController(
    SyncServiceProvider::getSyncJobForEditService(),
    SyncServiceProvider::updateSyncJobService(),
    EngineServiceProvider::listAuthConfigsService(),
    SyncServiceProvider::fieldsTargetCatalog(),
);

if (isset($_POST['update'])) {
    // GLPI 11 validates CSRF automatically in CheckCsrfListener before
    // loading legacy front controllers. Do not validate the same token
    // a second time here: successful validation consumes the token.
    $controller->update($_POST);
} else {
    Html::header(
        __('Edit synchronization job', 'uxconnector'),
        $_SERVER['PHP_SELF'],
        'tools',
        'PluginUxconnectorMenu',
        'syncjob',
    );

    $controller->showForm($_GET);

    Html::footer();
}
