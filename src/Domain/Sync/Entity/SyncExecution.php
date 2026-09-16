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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Entity;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Event\SyncExecutionFinishedEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/**
 * SyncExecution is its own aggregate root, deliberately separate
 * from SyncJob: a job is a stable *configuration*, while an
 * execution is one immutable-once-finished *fact* about a specific
 * run of it. Modelling them as one aggregate would force every
 * execution record to load and re-save the (unrelated) job
 * configuration, and would make the job's own optimistic locking
 * contend with a potentially high-frequency history log.
 *
 * RULE-ENTITY-001 to 005 apply.
 */
final class SyncExecution
{
    /**
     * @var list<DomainEvent>
     */
   private array $events = [];

   private ?SyncExecutionId $id = null;

   private SyncExecutionStatus $status;

   private DateTimeImmutable $startedAt;

   private ?DateTimeImmutable $finishedAt = null;

   private int $itemsProcessed = 0;

   private int $itemsFailed = 0;

   private ?string $errorMessage = null;

   private function __construct(
        private readonly SyncJobId $syncJobId
    ) {
       $this->status = SyncExecutionStatus::PENDING;
       $this->startedAt = new DateTimeImmutable();
   }

    /**
     * Starts a new execution for the given SyncJob.
     */
   public static function start(SyncJobId $syncJobId): self {
       $execution = new self($syncJobId);
       $execution->status = SyncExecutionStatus::RUNNING;

       return $execution;
   }

    /**
     * Rehydrates an existing SyncExecution from persistence. Never
     * records Domain Events.
     */
   public static function reconstitute(
        SyncExecutionId $id,
        SyncJobId $syncJobId,
        SyncExecutionStatus $status,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $finishedAt,
        int $itemsProcessed,
        int $itemsFailed,
        ?string $errorMessage
    ): self {
       $execution = new self($syncJobId);
       $execution->id = $id;
       $execution->status = $status;
       $execution->startedAt = $startedAt;
       $execution->finishedAt = $finishedAt;
       $execution->itemsProcessed = $itemsProcessed;
       $execution->itemsFailed = $itemsFailed;
       $execution->errorMessage = $errorMessage;

       return $execution;
   }

   public function assignId(SyncExecutionId $id): void {
      if ($this->id !== null) {
          throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncExecutionStateException::cannotAssignIdTwice();
      }

       $this->id = $id;
   }

    /**
     * Records the outcome of a finished run. Status is derived, not
     * chosen by the caller:
     *
     * - zero failed items            -> SUCCESS
     * - at least one success AND one
     *   failure                      -> PARTIAL
     * - zero successes, at least one
     *   failure                      -> FAILED (nothing usable came
     *                                   out of this run — it is not a
     *                                   "partial" success)
     *
     * A total, earlier failure (the external system unreachable, HTTP
     * error, ...) goes through fail() instead, with an explanatory
     * message — complete() is only for a run that reached the point
     * of actually processing individual items.
     */
   public function complete(int $itemsProcessed, int $itemsFailed): void {
       $this->guardNotAlreadyFinished();

      if ($itemsProcessed < 0) {
          throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncExecutionStateException::negativeItemCount('processed');
      }

      if ($itemsFailed < 0) {
          throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncExecutionStateException::negativeItemCount('failed');
      }

       $this->itemsProcessed = $itemsProcessed;
       $this->itemsFailed = $itemsFailed;
       $this->status = $this->deriveCompletionStatus($itemsProcessed, $itemsFailed);
       $this->finishedAt = new DateTimeImmutable();

       $this->recordFinishedEvent();
   }

   private function deriveCompletionStatus(int $itemsProcessed, int $itemsFailed): SyncExecutionStatus {
      if ($itemsFailed === 0) {
          return SyncExecutionStatus::SUCCESS;
      }

      if ($itemsProcessed === 0) {
          return SyncExecutionStatus::FAILED;
      }

       return SyncExecutionStatus::PARTIAL;
   }

    /**
     * Records a total failure — nothing usable was processed.
     */
   public function fail(string $errorMessage): void {
       $this->guardNotAlreadyFinished();

       $this->status = SyncExecutionStatus::FAILED;
       $this->errorMessage = $errorMessage;
       $this->finishedAt = new DateTimeImmutable();

       $this->recordFinishedEvent();
   }

   public function id(): ?SyncExecutionId {
       return $this->id;
   }

   public function syncJobId(): SyncJobId {
       return $this->syncJobId;
   }

   public function status(): SyncExecutionStatus {
       return $this->status;
   }

   public function startedAt(): DateTimeImmutable {
       return $this->startedAt;
   }

   public function finishedAt(): ?DateTimeImmutable {
       return $this->finishedAt;
   }

   public function itemsProcessed(): int {
       return $this->itemsProcessed;
   }

   public function itemsFailed(): int {
       return $this->itemsFailed;
   }

   public function errorMessage(): ?string {
       return $this->errorMessage;
   }

    /**
     * @return list<DomainEvent>
     */
   public function releaseEvents(): array {
       $events = $this->events;
       $this->events = [];

       return $events;
   }

   private function guardNotAlreadyFinished(): void {
      if ($this->status->isFinished()) {
          throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncExecutionStateException::alreadyFinished($this->status);
      }
   }

   private function recordFinishedEvent(): void {
      if ($this->id === null) {
          // Not yet persisted: the event will be captured by the
          // Application Service right after assignId(), following
          // the same pattern as CreateAuthConfigService — see its
          // class doc-block for why events are never released after
          // a Repository reload.
          return;
      }

       $this->events[] = new SyncExecutionFinishedEvent(
           $this->id,
           $this->syncJobId,
           $this->status,
           $this->itemsProcessed,
           $this->itemsFailed
       );
   }
}
