<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Copilot\CopilotServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Security\CopilotApiKeyAuthenticator;
use GlpiPlugin\Uxconnector\Presentation\Copilot\OpenApiDocumentFactory;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$path = trim((string) ($_GET['path'] ?? ''), '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// OpenAPI is intentionally public: it contains no credentials or customer data.
if ($path === 'openapi.json' && $method === 'GET') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $origin = $scheme . '://' . $host;
    $document = (new OpenApiDocumentFactory())->create($origin);
    $document['x-uxconnector-routing'] = [
        'openapi_endpoint' => $origin . '/plugins/uxconnector/front/copilot-openapi.php',
        'legacy_entrypoint' => $origin . '/plugins/uxconnector/front/copilot.php?path=',
    ];
    echo json_encode($document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return;
}

$key = trim((string) ($_SERVER['HTTP_X_UXCONNECTOR_COPILOT_KEY'] ?? ''));
$client = (new CopilotApiKeyAuthenticator())->authenticate($key);
if ($client === null) {
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(401);
    echo json_encode(['error' => 'unauthorized']);
    return;
}

try {
   if ($path === 'capabilities' && $method === 'GET') {
       echo json_encode(CopilotServiceProvider::providerForClient($client)->capabilities(), JSON_UNESCAPED_SLASHES);
       return;
   }

   if ($path === 'tickets/search' && $method === 'POST') {
       $payload = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
      if (!is_array($payload)) {
          throw new \InvalidArgumentException('JSON body must be an object.');
      }
       echo json_encode(CopilotServiceProvider::searchTicketsService($client)->execute($payload), JSON_UNESCAPED_SLASHES);
       return;
   }

   if ($method === 'GET' && preg_match('#^tickets/(\d+)/context$#D', $path, $m) === 1) {
       echo json_encode(CopilotServiceProvider::getTicketContextService($client)->execute((int) $m[1]), JSON_UNESCAPED_SLASHES);
       return;
   }

    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(404);
    echo json_encode(['error' => 'not_found']);
} catch (\JsonException|\InvalidArgumentException $e) {
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(400);
    echo json_encode(['error' => 'invalid_request', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    Toolbox::logInFile('uxconnector', sprintf("Copilot gateway failed: %s\n", $e->getMessage()));
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(502);
    echo json_encode(['error' => 'upstream_failure']);
}
