<?php

/** Public stateless machine-to-machine endpoint for inbound interactions. */

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Security\WebhookReplayException;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

$maxBytes = 2 * 1024 * 1024;
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error' => 'payload_too_large']);
    return;
}

$raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
if ($raw === false || strlen($raw) > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error' => 'payload_too_large']);
    return;
}

try {
    // Parse bounded JSON before consuming the signature, without performing any business action.
    $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new \RuntimeException('Webhook payload must be a JSON object.');
    }
    $key = SyncServiceProvider::webhookRequestAuthenticator()->authenticate($_SERVER, $raw);

    $result = SyncServiceProvider::processInboundWebhookService()->execute($key, $payload);
    http_response_code(200);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES);
} catch (WebhookReplayException) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'request_already_received']);
} catch (\Throwable $exception) {
    // Exception messages can contain upstream data. Log only the exception type at this boundary.
    Toolbox::logInFile('uxconnector', sprintf("Webhook processing failed (%s).\n", $exception::class));
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'interaction_failed']);
}
