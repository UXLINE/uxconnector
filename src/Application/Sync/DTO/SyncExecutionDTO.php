<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\DTO;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;

/**
 * Read-only, serializable representation of a SyncExecution, safe to
 * hand to a template.
 *
 * RULE-DTO-001/002/003 : immutable, the Entity is never exposed
 * directly, zero business logic — only a one-way conversion from the
 * aggregate's Value Objects/Enums to plain scalars.
 */
final readonly class SyncExecutionDTO
{
   public function __construct(
        public int $id,
        public int $syncJobId,
        public string $status,
        public string $statusLabel,
        public string $startedAt,
        public ?string $finishedAt,
        public ?int $durationSeconds,
        public int $itemsProcessed,
        public int $itemsFailed,
        public ?string $errorMessage
    ) {
   }

   public static function fromEntity(SyncExecution $execution): self {
       $id = $execution->id();

      if ($id === null) {
          throw new \LogicException(
              'Cannot build a SyncExecutionDTO from a SyncExecution that has not been persisted yet.'
          );
      }

       $finishedAt = $execution->finishedAt();

       return new self(
           id: $id->value(),
           syncJobId: $execution->syncJobId()->value(),
           status: $execution->status()->value,
           statusLabel: $execution->status()->label(),
           startedAt: $execution->startedAt()->format('Y-m-d H:i:s'),
           finishedAt: $finishedAt?->format('Y-m-d H:i:s'),
           durationSeconds: $finishedAt !== null
               ? $finishedAt->getTimestamp() - $execution->startedAt()->getTimestamp()
               : null,
           itemsProcessed: $execution->itemsProcessed(),
           itemsFailed: $execution->itemsFailed(),
           errorMessage: $execution->errorMessage()
       );
   }
}
