<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Adapter;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketSourceResult;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticTicketSourceInterface;

final readonly class PreparedSemanticTicketSource implements SemanticTicketSourceInterface
{
    public function __construct(private SemanticTicketSourceResult $result)
    {
    }

    public function fetch(array $criteria): SemanticTicketSourceResult
    {
        return $this->result;
    }
}
