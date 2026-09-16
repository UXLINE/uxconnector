<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Port;

use GlpiPlugin\Uxconnector\Domain\Copilot\Model\CanonicalTicket;

interface ItsmDataProviderInterface
{
       /**
    * @param array<string, mixed> $criteria
    *
    * @return list<CanonicalTicket>
    */
   public function searchTickets(array $criteria): array;

    /** @return array<string,mixed> */
   public function getTicketContext(int $ticketId): array;

    /** @return array<string,mixed> */
   public function capabilities(): array;
}
