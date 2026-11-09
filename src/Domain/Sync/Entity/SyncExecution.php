<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Entity;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Event\SyncExecutionFinishedEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncExecutionStateException;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/** Aggregate root describing one queued/running/finished synchronization execution. */
final class SyncExecution
{
    /** @var list<DomainEvent> */
    private array $events = [];
    private ?SyncExecutionId $id = null;
    private SyncExecutionStatus $status;
    private DateTimeImmutable $startedAt;
    private ?DateTimeImmutable $finishedAt = null;
    private int $itemsProcessed = 0;
    private int $itemsCreated = 0;
    private int $itemsUpdated = 0;
    private int $itemsSkipped = 0;
    private int $itemsWarnings = 0;
    private int $itemsFailed = 0;
    private ?string $errorMessage = null;
    private ?string $phase = null;
    private ?string $cursor = null;
    private int $page = 0;
    private ?string $sourceId = null;
    private ?string $lastSuccessfulSourceId = null;
    private ?DateTimeImmutable $lastSuccessAt = null;
    private ?DateTimeImmutable $heartbeatAt = null;
    private bool $resumeAvailable = false;
    private string $triggerSource = 'scheduled';
    private bool $stopRequested = false;
    private ?string $resumePath = null;

    private function __construct(private readonly SyncJobId $syncJobId)
    {
        $this->status = SyncExecutionStatus::PENDING;
        $this->startedAt = new DateTimeImmutable();
    }

    public static function queue(SyncJobId $syncJobId, string $triggerSource = 'manual'): self
    {
        $execution = new self($syncJobId);
        $execution->triggerSource = $triggerSource;
        return $execution;
    }

    public static function start(SyncJobId $syncJobId, string $triggerSource = 'scheduled'): self
    {
        $execution = self::queue($syncJobId, $triggerSource);
        $execution->begin();
        return $execution;
    }

    public static function reconstitute(
        SyncExecutionId $id,
        SyncJobId $syncJobId,
        SyncExecutionStatus $status,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $finishedAt,
        int $itemsProcessed,
        int $itemsFailed,
        ?string $errorMessage,
        int $itemsCreated = 0,
        int $itemsUpdated = 0,
        int $itemsSkipped = 0,
        int $itemsWarnings = 0,
        ?string $phase = null,
        ?string $cursor = null,
        int $page = 0,
        ?string $sourceId = null,
        ?string $lastSuccessfulSourceId = null,
        ?DateTimeImmutable $lastSuccessAt = null,
        ?DateTimeImmutable $heartbeatAt = null,
        bool $resumeAvailable = false,
        string $triggerSource = 'scheduled',
        bool $stopRequested = false,
        ?string $resumePath = null
    ): self {
        $execution = new self($syncJobId);
        $execution->id = $id;
        $execution->status = $status;
        $execution->startedAt = $startedAt;
        $execution->finishedAt = $finishedAt;
        $execution->itemsProcessed = $itemsProcessed;
        $execution->itemsCreated = $itemsCreated;
        $execution->itemsUpdated = $itemsUpdated;
        $execution->itemsSkipped = $itemsSkipped;
        $execution->itemsWarnings = $itemsWarnings;
        $execution->itemsFailed = $itemsFailed;
        $execution->errorMessage = $errorMessage;
        $execution->phase = $phase;
        $execution->cursor = $cursor;
        $execution->page = $page;
        $execution->sourceId = $sourceId;
        $execution->lastSuccessfulSourceId = $lastSuccessfulSourceId;
        $execution->lastSuccessAt = $lastSuccessAt;
        $execution->heartbeatAt = $heartbeatAt;
        $execution->resumeAvailable = $resumeAvailable;
        $execution->triggerSource = $triggerSource;
        $execution->stopRequested = $stopRequested;
        $execution->resumePath = $resumePath;
        return $execution;
    }

    public function assignId(SyncExecutionId $id): void
    {
        if ($this->id !== null) {
            throw InvalidSyncExecutionStateException::cannotAssignIdTwice();
        }
        $this->id = $id;
    }

    public function requeue(): void
    {
        if ($this->status !== SyncExecutionStatus::INTERRUPTED || !$this->resumeAvailable) {
            throw new \RuntimeException('Only an interrupted resumable execution can be queued again.');
        }
        $this->status = SyncExecutionStatus::PENDING;
        $this->finishedAt = null;
        $this->errorMessage = null;
        $this->heartbeatAt = new DateTimeImmutable();
        $this->stopRequested = false;
    }

    public function begin(): void
    {
        if (!in_array($this->status, [SyncExecutionStatus::PENDING, SyncExecutionStatus::INTERRUPTED], true)) {
            throw new \RuntimeException(sprintf('Execution cannot start from status "%s".', $this->status->value));
        }
        $this->status = SyncExecutionStatus::RUNNING;
        $this->finishedAt = null;
        $this->errorMessage = null;
        $this->resumeAvailable = false;
        $this->stopRequested = false;
        $this->heartbeatAt = new DateTimeImmutable();
    }

    public function heartbeat(?string $phase = null): void
    {
        if ($this->status !== SyncExecutionStatus::RUNNING) {
            return;
        }
        if ($phase !== null && $phase !== '') {
            $this->phase = $phase;
        }
        $this->heartbeatAt = new DateTimeImmutable();
    }

    public function updateProgress(
        int $processed,
        int $failed,
        ?string $phase = null,
        ?string $cursor = null,
        int $page = 0,
        ?string $sourceId = null,
        ?string $lastSuccessfulSourceId = null,
        int $created = 0,
        int $updated = 0,
        int $skipped = 0,
        int $warnings = 0
    ): void {
        if ($this->status !== SyncExecutionStatus::RUNNING) {
            return;
        }
        $this->itemsProcessed = max(0, $processed);
        $this->itemsFailed = max(0, $failed);
        $this->itemsCreated = max(0, $created);
        $this->itemsUpdated = max(0, $updated);
        $this->itemsSkipped = max(0, $skipped);
        $this->itemsWarnings = max(0, $warnings);
        $this->phase = $phase;
        $this->cursor = $cursor;
        $this->page = max(0, $page);
        $this->sourceId = $sourceId;
        if ($lastSuccessfulSourceId !== null && $lastSuccessfulSourceId !== '') {
            $this->lastSuccessfulSourceId = $lastSuccessfulSourceId;
            $this->lastSuccessAt = new DateTimeImmutable();
        }
        $this->heartbeatAt = new DateTimeImmutable();
    }

    public function complete(int $itemsProcessed, int $itemsFailed): void
    {
        $this->completeDetailed($itemsProcessed, $itemsFailed, 0, 0, 0, 0);
    }

    public function completeDetailed(
        int $itemsProcessed,
        int $itemsFailed,
        int $itemsCreated,
        int $itemsUpdated,
        int $itemsSkipped,
        int $itemsWarnings
    ): void {
        $this->guardNotAlreadyFinished();
        foreach ([$itemsProcessed, $itemsFailed, $itemsCreated, $itemsUpdated, $itemsSkipped, $itemsWarnings] as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Execution counters cannot be negative.');
            }
        }
        $this->itemsProcessed = $itemsProcessed;
        $this->itemsFailed = $itemsFailed;
        $this->itemsCreated = $itemsCreated;
        $this->itemsUpdated = $itemsUpdated;
        $this->itemsSkipped = $itemsSkipped;
        $this->itemsWarnings = $itemsWarnings;
        $this->status = $itemsFailed > 0 || $itemsWarnings > 0
            ? SyncExecutionStatus::SUCCESS_WITH_WARNINGS
            : SyncExecutionStatus::SUCCESS;
        if ($itemsProcessed === 0 && $itemsFailed > 0) {
            $this->status = SyncExecutionStatus::FAILED;
        }
        $this->finishedAt = new DateTimeImmutable();
        $this->resumeAvailable = false;
        $this->recordFinishedEvent();
    }

    public function fail(string $errorMessage): void
    {
        $this->guardNotAlreadyFinished();
        $this->status = SyncExecutionStatus::FAILED;
        $this->errorMessage = $errorMessage;
        $this->finishedAt = new DateTimeImmutable();
        $this->resumeAvailable = false;
        $this->recordFinishedEvent();
    }

    public function interrupt(string $errorMessage): void
    {
        if ($this->status->isFinished() && $this->status !== SyncExecutionStatus::INTERRUPTED) {
            throw InvalidSyncExecutionStateException::alreadyFinished($this->status);
        }
        $this->status = SyncExecutionStatus::INTERRUPTED;
        $this->errorMessage = $errorMessage;
        $this->finishedAt = new DateTimeImmutable();
        $this->resumeAvailable = true;
        $this->recordFinishedEvent();
    }

    public function id(): ?SyncExecutionId
    {
        return $this->id;
    }
    public function syncJobId(): SyncJobId
    {
        return $this->syncJobId;
    }
    public function status(): SyncExecutionStatus
    {
        return $this->status;
    }
    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }
    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }
    public function itemsProcessed(): int
    {
        return $this->itemsProcessed;
    }
    public function itemsCreated(): int
    {
        return $this->itemsCreated;
    }
    public function itemsUpdated(): int
    {
        return $this->itemsUpdated;
    }
    public function itemsSkipped(): int
    {
        return $this->itemsSkipped;
    }
    public function itemsWarnings(): int
    {
        return $this->itemsWarnings;
    }
    public function itemsFailed(): int
    {
        return $this->itemsFailed;
    }
    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }
    public function phase(): ?string
    {
        return $this->phase;
    }
    public function cursor(): ?string
    {
        return $this->cursor;
    }
    public function page(): int
    {
        return $this->page;
    }
    public function sourceId(): ?string
    {
        return $this->sourceId;
    }
    public function lastSuccessfulSourceId(): ?string
    {
        return $this->lastSuccessfulSourceId;
    }
    public function lastSuccessAt(): ?DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }
    public function heartbeatAt(): ?DateTimeImmutable
    {
        return $this->heartbeatAt;
    }
    public function resumeAvailable(): bool
    {
        return $this->resumeAvailable;
    }
    public function triggerSource(): string
    {
        return $this->triggerSource;
    }
    public function stopRequested(): bool
    {
        return $this->stopRequested;
    }
    public function resumePath(): ?string
    {
        return $this->resumePath;
    }
    public function setResumePath(?string $path): void
    {
        $this->resumePath = $path;
    }

    /** @return list<DomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    private function guardNotAlreadyFinished(): void
    {
        if ($this->status->isFinished()) {
            throw InvalidSyncExecutionStateException::alreadyFinished($this->status);
        }
    }

    private function recordFinishedEvent(): void
    {
        if ($this->id === null) {
            return;
        }
        $this->events[] = new SyncExecutionFinishedEvent(
            $this->id,
            $this->syncJobId,
            $this->status,
            $this->itemsProcessed,
            $this->itemsFailed,
        );
    }
}
