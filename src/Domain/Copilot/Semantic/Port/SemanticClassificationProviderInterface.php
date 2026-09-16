<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketClassification;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketInput;

interface SemanticClassificationProviderInterface
{
    /**
     * @param list<SemanticTicketInput> $tickets
     * @return list<SemanticTicketClassification>
     */
   public function classify(array $tickets): array;
}
