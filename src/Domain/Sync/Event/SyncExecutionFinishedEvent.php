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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Event;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/**
 * Raised once a SyncExecution reaches a terminal status (SUCCESS,
 * PARTIAL or FAILED). Intended subscribers: notification handlers,
 * dashboard cache invalidation, audit trail — none of which the
 * Domain knows about (RULE-061).
 */
final readonly class SyncExecutionFinishedEvent implements DomainEvent
{
   private DateTimeImmutable $occurredAt;

   public function __construct(
        public SyncExecutionId $executionId,
        public SyncJobId $syncJobId,
        public SyncExecutionStatus $status,
        public int $itemsProcessed,
        public int $itemsFailed
    ) {
       $this->occurredAt = new DateTimeImmutable();
   }

   public function occurredAt(): DateTimeImmutable {
       return $this->occurredAt;
   }

   public function name(): string {
       return 'sync.sync_execution.finished';
   }
}
