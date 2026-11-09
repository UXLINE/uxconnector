<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO;

/**
 * Result of selecting factual GLPI tickets for semantic classification.
 *
 * candidateCount is the number of matching tickets observed inside the source
 * scan. selectedCount is the number actually returned after max_tickets.
 */
final readonly class SemanticTicketSourceResult
{
    /**
     * @param list<SemanticTicketInput> $tickets
     */
    public function __construct(
        public array $tickets,
        public int $candidateCount,
        public int $selectedCount,
        public bool $selectionTruncated,
        public bool $sourceScanTruncated,
        public int $textTruncatedTickets
    ) {
        if ($this->candidateCount < 0 || $this->selectedCount < 0) {
            throw new \InvalidArgumentException('Semantic ticket source counts must not be negative.');
        }

        if ($this->selectedCount !== count($this->tickets)) {
            throw new \InvalidArgumentException('Semantic ticket source selectedCount must match tickets length.');
        }

        if ($this->selectedCount > $this->candidateCount) {
            throw new \InvalidArgumentException('Semantic ticket source selectedCount cannot exceed candidateCount.');
        }
    }
}
