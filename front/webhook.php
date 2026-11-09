<?php

/** Public stateless machine-to-machine endpoint for inbound interactions. */
declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Sync\Security\WebhookRequestAuthenticator;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(405);
    header('Allow: POST');
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

$maxBytes = 2 * 1024 * 1024;
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBytes) {
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(413);
    echo json_encode(['error' => 'payload_too_large']);
    return;
}

$raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
if ($raw === false || strlen($raw) > $maxBytes) {
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(413);
    echo json_encode(['error' => 'payload_too_large']);
    return;
}

try {
    $key = WebhookRequestAuthenticator::fromEnvironment()->authenticate($_SERVER, $_GET, $raw);

    $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new \RuntimeException('Webhook payload must be a JSON object.');
    }

    $result = SyncServiceProvider::processInboundWebhookService()->execute($key, $payload);
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(200);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $exception) {
    // Never expose stack traces, SQL or infrastructure details to the caller.
    Toolbox::logInFile('uxconnector', sprintf("Webhook processing failed: %s\n", $exception->getMessage()));
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(400);
    echo json_encode(['ok' => false, 'error' => 'interaction_failed']);
}
