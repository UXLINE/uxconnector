<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\SyncExecutionController;

Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException(['POST']);
}
$controller = new SyncExecutionController(
    SyncServiceProvider::listSyncFailuresService(),
    SyncServiceProvider::resumeSyncExecutionService(),
    SyncServiceProvider::retryFailedItemsService(),
);
$controller->resume($_POST);
