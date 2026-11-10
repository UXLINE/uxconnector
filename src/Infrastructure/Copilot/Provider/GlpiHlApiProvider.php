<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Provider;

use GlpiPlugin\Uxconnector\Domain\Copilot\Model\CanonicalTicket;
use GlpiPlugin\Uxconnector\Domain\Copilot\Port\ItsmDataProviderInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;

final readonly class GlpiHlApiProvider implements ItsmDataProviderInterface
{
    public function __construct(
        private AuthConfig $connection,
        private HttpEngineInterface $http,
        private GlpiHlTicketNormalizer $normalizer = new GlpiHlTicketNormalizer()
    ) {
        if (!$this->connection->isUsable()) {
            throw new \RuntimeException('The GLPI HL connection must be active before Copilot can use it.');
        }
    }

    public function capabilities(): array
    {
        return [
            'provider' => 'glpi-hl-api',
            'read_only' => true,
            'tools' => ['searchTickets', 'getTicketContext'],
            'upstream_routes' => [
                '/Assistance/Ticket',
                '/Assistance/Ticket/{id}',
                '/Assistance/Ticket/{id}/TeamMember',
                '/Assistance/Ticket/{id}/Timeline',
                '/Assistance/Ticket/{id}/Timeline/Followup',
                '/Assistance/Ticket/{id}/Timeline/Solution',
                '/Assistance/Ticket/{id}/Timeline/Task',
                '/Assistance/Ticket/{id}/Timeline/Validation',
                '/Assistance/Ticket/{id}/KBArticle',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<CanonicalTicket>
     */
    public function searchTickets(array $criteria): array
    {
        // API-HL filtering syntax differs across builds. Lot 1 deliberately
        // uses a stable GET and applies conservative canonical filtering here.
        $rows = $this->extractCollection($this->getJson('/Assistance/Ticket'));
        $query = mb_strtolower(trim((string) ($criteria['query'] ?? '')));
        $status = $this->stringList($criteria['status'] ?? []);
        $limit = max(1, min(100, (int) ($criteria['limit'] ?? 25)));
        $dateFrom = trim((string) ($criteria['date_from'] ?? ''));
        $dateTo = trim((string) ($criteria['date_to'] ?? ''));

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ticket = $this->normalizer->normalize($row);
            if ($ticket->id <= 0) {
                continue;
            }
            if (
                $query !== '' && !str_contains(mb_strtolower($ticket->title . ' ' . $ticket->description . ' ' . (
                            $ticket->category ?? '')), $query)
            ) {
                continue;
            }
            if ($status !== [] && !in_array(mb_strtolower((string) $ticket->status), $status, true)) {
                continue;
            }
            if (
                $dateFrom !== '' && $ticket->createdAt !== null && strcmp(
                    substr($ticket->createdAt, 0, 10),
                    $dateFrom
                ) < 0
            ) {
                continue;
            }
            if (
                $dateTo !== '' && $ticket->createdAt !== null && strcmp(
                    substr($ticket->createdAt, 0, 10),
                    $dateTo
                ) > 0
            ) {
                continue;
            }
            $result[] = $ticket;
            if (count($result) >= $limit) {
                break;
            }
        }
        return $result;
    }

    public function getTicketContext(int $ticketId): array
    {
        $base = '/Assistance/Ticket/' . $ticketId;
        $ticketPayload = $this->getJson($base);
        $ticketRow = $this->extractObject($ticketPayload);
        if ($ticketRow === []) {
            throw new \RuntimeException(sprintf('Ticket %d was not returned by GLPI HL API.', $ticketId));
        }

        return [
            'ticket' => $this->normalizer->normalize($ticketRow)->toArray(),
            'team_members' => $this->safeGet($base . '/TeamMember'),
            'timeline' => $this->safeGet($base . '/Timeline'),
            'followups' => $this->safeGet($base . '/Timeline/Followup'),
            'solutions' => $this->safeGet($base . '/Timeline/Solution'),
            'tasks' => $this->safeGet($base . '/Timeline/Task'),
            'validations' => $this->safeGet($base . '/Timeline/Validation'),
            'knowledge_articles' => $this->safeGet($base . '/KBArticle'),
        ];
    }

    /** @return array<string,mixed>|list<mixed> */
    private function getJson(string $path): array
    {
        $response = $this->http->execute($this->connection, new HttpRequest(
            HttpMethod::GET,
            $path,
            ['Accept' => 'application/json']
        ));
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new \RuntimeException(sprintf('GLPI HL API returned HTTP %d for %s.', $response->statusCode, $path));
        }
        $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('GLPI HL API response is not a JSON object/array.');
        }
        return $decoded;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function safeGet(string $path): array
    {
        try {
            return $this->getJson($path);
        } catch (\Throwable $e) {
            return ['available' => false, 'error' => 'upstream_subresource_unavailable'];
        }
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<mixed>
     */
    private function extractCollection(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach (['data', 'items', 'results', 'records', 'tickets'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_is_list($payload[$key]) ? $payload[$key] : array_values($payload[$key]);
            }
        }
        return [];
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function extractObject(array $payload): array
    {
        if (!array_is_list($payload) && isset($payload['id'])) {
            return $payload;
        }
        foreach (['data', 'item', 'ticket'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && !array_is_list($payload[$key])) {
                return $payload[$key];
            }
        }
        $rows = $this->extractCollection($payload);
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [mb_strtolower(trim($value))];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($v) => is_scalar($v) ? mb_strtolower(
            trim((string) $v)
        ) : '', $value)));
    }
}
