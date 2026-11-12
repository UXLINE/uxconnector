<?php

declare(strict_types=1);

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Security\WebhookRequestAuthenticator;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\ProfileRights;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

// The GLPI kernel validates the session's CSRF token before dispatching this POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new MethodNotAllowedHttpException(['POST']);
}

Session::checkRight('plugin_uxconnector_authconfig', READ);
Session::checkRight('plugin_uxconnector_syncjob', READ);
Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
$action = $_POST['credential_action'] ?? null;
if (!in_array($action, ['reveal_signing', 'reveal_bearer', 'rotate_signing'], true)) {
    throw new BadRequestHttpException();
}
if ($action === 'reveal_bearer' && WebhookRequestAuthenticator::requiresHmac()) {
    throw new BadRequestHttpException();
}
Session::checkRight(ProfileRights::WEBHOOK_CREDENTIALS, $action === 'rotate_signing' ? UPDATE : READ);

$id = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    throw new BadRequestHttpException();
}

$returnUrl = '/plugins/uxconnector/front/connections.php';
try {
    $jobs = SyncServiceProvider::syncJobRepository();
    $job = $jobs->findById(new SyncJobId($id));
    if (
        $job === null || $job->triggerType() !== InteractionTrigger::WEBHOOK
        || $job->direction() !== SyncDirection::INBOUND
    ) {
        throw new \DomainException('Inbound webhook required.');
    }
    SyncServiceProvider::jobEntityAccess()->assertStrategy($job->entityAssignmentStrategy());
    $returnUrl = '/plugins/uxconnector/front/connection.php?id='
        . $job->authConfigReference()->value() . '#interactions';

    $value = $action === 'reveal_bearer' ? $job->webhookKey() : $job->webhookSigningSecret();
    if ($value === null) {
        throw new \DomainException('Webhook credential unavailable.');
    }

    $audit = sprintf("action=%s job_id=%d user_id=%d\n", $action, $id, (int) Session::getLoginUserID());
    if (!Toolbox::logInFile('uxconnector-webhook-audit', $audit, true, false)) {
        throw new \RuntimeException('Webhook audit unavailable.');
    }
    if ($action === 'rotate_signing') {
        $value = bin2hex(random_bytes(32));
        $jobs->rotateWebhookSigningSecret($job, $value);
    }
} catch (\Throwable) {
    Session::addMessageAfterRedirect(__('Unable to access the webhook credential.', 'uxconnector'), false, ERROR);
    Html::redirect($returnUrl);
    return;
}

header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
Html::header(__('Webhook credentials', 'uxconnector'), $_SERVER['PHP_SELF'], 'tools', 'PluginUxconnectorMenu');
TemplateRenderer::getInstance()->display('@uxconnector/connection/webhook-credential.html.twig', [
    'job_id' => $id,
    'bearer' => $action === 'reveal_bearer',
    'rotated' => $action === 'rotate_signing',
    'credential' => $value,
    'return_url' => $returnUrl,
]);
Html::footer();
