<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Port\ItsmDataProviderInterface;

final readonly class SearchTicketsService
{
    public function __construct(private ItsmDataProviderInterface $provider)
    {
    }

    /**
    * @param array<string, mixed> $criteria
    *
    * @return array{
    *     count: int,
    *     limit: int,
    *     tickets: list<array<string, mixed>>
    * }
    */
    public function execute(array $criteria): array
    {
        $limit = max(1, min(100, (int) ($criteria['limit'] ?? 25)));
        $criteria['limit'] = $limit;
        $tickets = $this->provider->searchTickets($criteria);

        return [
            'count' => count($tickets),
            'limit' => $limit,
            'tickets' => array_map(static fn($ticket) => $ticket->toArray(), $tickets),
        ];
    }
}
