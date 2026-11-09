<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException(['POST']);
}
try {
    SyncServiceProvider::requestStopSyncExecutionService()->execute(
        new SyncExecutionId((int) ($_POST['id'] ?? 0)),
    );
    Session::addMessageAfterRedirect(__('Stop requested: the worker will finish its current item before interrupting safely.', 'uxconnector'));
} catch (\Throwable $exception) {
    Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
}
Html::redirect('/plugins/uxconnector/front/syncjob.php?history=1&id=' . (int) ($_POST['job_id'] ?? 0));
