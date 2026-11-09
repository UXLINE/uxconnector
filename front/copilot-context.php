<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Bootstrap\CopilotGatewayResponderFactory;

try {
    CopilotGatewayResponderFactory::create()->ticketContext();
} catch (\InvalidArgumentException $e) {
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_request','message' => $e->getMessage()]);
} catch (\Throwable $e) {
    Toolbox::logInFile('uxconnector', "Copilot context failed: {$e->getMessage()}\n");
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(502);
    header('Content-Type: application/json');
    echo '{"error":"upstream_failure"}';
}
