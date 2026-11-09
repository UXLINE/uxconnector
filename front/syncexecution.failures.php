<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\SyncExecutionController;

Session::checkRight('plugin_uxconnector_syncjob', READ);
$controller = new SyncExecutionController(
    SyncServiceProvider::listSyncFailuresService(),
    SyncServiceProvider::resumeSyncExecutionService(),
    SyncServiceProvider::retryFailedItemsService(),
);
if (isset($_POST['retry_failed'])) {
    Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
    $controller->retryFailed($_POST);
} elseif (isset($_GET['export'])) {
    $controller->exportCsv($_GET);
} else {
    Html::header(__('Synchronization failures', 'uxconnector'), $_SERVER['PHP_SELF'], 'tools', 'PluginUxconnectorMenu', 'syncjob');
    $controller->showFailures($_GET);
    Html::footer();
}
