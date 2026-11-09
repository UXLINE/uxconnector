<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Bootstrap\CopilotGatewayResponderFactory;

try {
    CopilotGatewayResponderFactory::create()->analytics('technicians');
} catch (\JsonException|\InvalidArgumentException $e) {
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'invalid_request', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    Toolbox::logInFile('uxconnector', sprintf("Copilot analytics failed: %s\n", $e->getMessage()));
    \GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotHttpStatus::send(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'analytics_failure']);
}
