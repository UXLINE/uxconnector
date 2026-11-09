<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Gateway;

/** Application-facing port used by the stateless Copilot HTTP presentation. */
interface CopilotGatewayInterface
{
    /** @return array<string,mixed>|null */
    public function authenticate(string $apiKey): ?array;

    /** @param array<string,mixed> $client @return array<string,mixed> */
    public function capabilities(array $client): array;

    /** @param array<string,mixed> $client @param array<string,mixed> $payload @return array<string,mixed> */
    public function searchTickets(array $client, array $payload): array;

    /** @param array<string,mixed> $client @return array<string,mixed> */
    public function ticketContext(array $client, int $ticketId): array;

    /** @param array<string,mixed> $client @param array<string,mixed> $payload @return array<string,mixed> */
    public function analytics(array $client, string $tool, array $payload): array;
}
