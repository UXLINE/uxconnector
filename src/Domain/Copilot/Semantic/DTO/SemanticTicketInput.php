<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO;

/**
 * Minimum factual ticket payload sent to a semantic classification provider.
 *
 * declaredType and category are context only. The semantic class must remain a
 * distinct computed dimension.
 */
final readonly class SemanticTicketInput
{
   public function __construct(
        public int $ticketId,
        public string $title,
        public string $description,
        public ?string $declaredType = null,
        public ?string $category = null
    ) {
      if ($this->ticketId <= 0) {
          throw new \InvalidArgumentException('Semantic ticket id must be positive.');
      }
   }

    /** @return array<string, int|string|null> */
   public function toProviderArray(): array {
       return [
           'ticket_id' => $this->ticketId,
           'title' => $this->title,
           'description' => $this->description,
           'declared_type' => $this->declaredType,
           'itil_category' => $this->category,
       ];
   }
}
