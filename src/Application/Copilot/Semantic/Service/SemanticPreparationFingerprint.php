<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketSourceResult;

final class SemanticPreparationFingerprint
{
   /**
    * @param array<string, mixed> $criteria
    */
   public static function calculate(
        array $criteria,
        SemanticTicketSourceResult $sourceResult
    ): string {
       $canonical = [
           'classifier_version' => PowerPlatformSemanticPolicy::CLASSIFIER_VERSION,
           'criteria' => self::normalizeCriteria($criteria),
           'candidate_count' => $sourceResult->candidateCount,
           'selected_count' => $sourceResult->selectedCount,
           'ticket_ids' => array_map(
               static fn ($ticket): int => $ticket->ticketId,
               $sourceResult->tickets
           ),
       ];

       $json = json_encode(
           $canonical,
           JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
       );

      if (!is_string($json)) {
          throw new \RuntimeException('Unable to calculate semantic preparation fingerprint.');
      }

       return hash('sha256', $json);
   }

   /**
    * @param array<string, mixed> $criteria
    *
    * @return array{
    *     date_from: string,
    *     date_to: string,
    *     entity_id: int|null,
    *     category_id: int|null,
    *     type: string,
    *     max_tickets: int,
    *     result_limit: int
    * }
    */
   public static function normalizeCriteria(array $criteria): array {
       return [
           'date_from' => trim((string) ($criteria['date_from'] ?? '')),
           'date_to' => trim((string) ($criteria['date_to'] ?? '')),
           'entity_id' => isset($criteria['entity_id']) ? max(0, (int) $criteria['entity_id']) : null,
           'category_id' => isset($criteria['category_id']) ? max(0, (int) $criteria['category_id']) : null,
           'type' => trim((string) ($criteria['type'] ?? '')),
           'max_tickets' => max(1, min(10000, (int) ($criteria['max_tickets'] ?? 500))),
           'result_limit' => max(0, min(500, (int) ($criteria['result_limit'] ?? 100))),
       ];
   }
}
