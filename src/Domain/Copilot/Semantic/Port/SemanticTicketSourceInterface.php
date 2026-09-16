<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketSourceResult;

interface SemanticTicketSourceInterface
{
    /**
     * Supported V1 criteria:
     * - date_from / date_to (inclusive, creation date)
     * - entity_id
     * - category_id
     * - type: Incident|1|Request|Demande|2
     * - max_tickets: 1..10000, default 500
     *
     * @param array<string,mixed> $criteria
     */
   public function fetch(array $criteria): SemanticTicketSourceResult;
}
