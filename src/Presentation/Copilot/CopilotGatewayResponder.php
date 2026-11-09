<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Copilot;

use GlpiPlugin\Uxconnector\Application\Copilot\Gateway\CopilotGatewayInterface;

final class CopilotGatewayResponder
{
    public function __construct(private readonly CopilotGatewayInterface $gateway) {}

    public function capabilities(): void
    {
        $client = $this->authenticate();
        if ($client === null) {
            return;
        }
        $this->json(200, $this->gateway->capabilities($client));
    }

    public function searchTickets(): void
    {
        $client = $this->authenticate();
        if ($client === null) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json(405, ['error' => 'method_not_allowed']);
            return;
        }
        $payload = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON body must be an object.');
        }
        $this->json(200, $this->gateway->searchTickets($client, $payload));
    }

    public function ticketContext(): void
    {
        $client = $this->authenticate();
        if ($client === null) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $this->json(405, ['error' => 'method_not_allowed']);
            return;
        }
        $this->json(200, $this->gateway->ticketContext($client, (int) ($_GET['id'] ?? 0)));
    }

    public function analytics(string $tool): void
    {
        $client = $this->authenticate();
        if ($client === null) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json(405, ['error' => 'method_not_allowed']);
            return;
        }
        $payload = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON body must be an object.');
        }
        $this->json(200, $this->gateway->analytics($client, $tool, $payload));
    }

    /** @return array<string,mixed>|null */
    private function authenticate(): ?array
    {
        $key = trim((string) ($_SERVER['HTTP_X_UXCONNECTOR_COPILOT_KEY'] ?? ''));
        $client = $this->gateway->authenticate($key);
        if ($client === null) {
            $this->json(401, ['error' => 'unauthorized']);
            return null;
        }
        return $client;
    }

    /** @param array<string,mixed> $payload */
    private function json(int $status, array $payload): void
    {
        CopilotHttpStatus::send($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
