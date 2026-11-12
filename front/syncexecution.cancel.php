<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionBusyException;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;

Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException(['POST']);
}

$jobId = (int) ($_POST['job_id'] ?? 0);
try {
    SyncServiceProvider::jobEntityAccess()->assertJobId(new SyncJobId($jobId));
    $execution = SyncServiceProvider::syncExecutionRepository()->requestCancellation(
        new SyncExecutionId((int) ($_POST['id'] ?? 0)),
        new SyncJobId($jobId)
    );
    Session::addMessageAfterRedirect(
        $execution->status() === SyncExecutionStatus::CANCELLED
            ? __('Execution cancelled.', 'uxconnector')
            : __('Cancellation requested. The worker will stop at its next safe checkpoint.', 'uxconnector')
    );
} catch (SyncExecutionBusyException) {
    Session::addMessageAfterRedirect(__('This execution is no longer cancellable.', 'uxconnector'), false, ERROR);
} catch (\Throwable) {
    Session::addMessageAfterRedirect(__('Unable to cancel this execution.', 'uxconnector'), false, ERROR);
}
Html::redirect('/plugins/uxconnector/front/syncjob.php?history=1&id=' . $jobId);
