<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Port\ItsmDataProviderInterface;

final readonly class GetTicketContextService
{
    public function __construct(private ItsmDataProviderInterface $provider)
    {
    }

    /** @return array<string,mixed> */
    public function execute(int $ticketId): array
    {
        if ($ticketId <= 0) {
            throw new \InvalidArgumentException('ticketId must be a positive integer.');
        }
        return $this->provider->getTicketContext($ticketId);
    }
}
