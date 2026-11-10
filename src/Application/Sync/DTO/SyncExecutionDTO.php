<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\DTO;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;

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
        public int $itemsCreated,
        public int $itemsUpdated,
        public int $itemsSkipped,
        public int $itemsWarnings,
        public int $itemsFailed,
        public ?string $errorMessage,
        public ?string $phase,
        public ?string $cursor,
        public int $page,
        public ?string $sourceId,
        public ?string $lastSuccessfulSourceId,
        public ?string $lastSuccessAt,
        public ?string $heartbeatAt,
        public bool $resumeAvailable,
        public string $triggerSource,
        public bool $stopRequested,
        public bool $cancellationRequested = false
    ) {
    }

    public static function fromEntity(SyncExecution $execution): self
    {
        $id = $execution->id();
        if ($id === null) {
            throw new \LogicException('Cannot build a SyncExecutionDTO from a non-persisted execution.');
        }
        $finishedAt = $execution->finishedAt();
        return new self(
            $id->value(),
            $execution->syncJobId()->value(),
            $execution->status()->value,
            $execution->status()->label(),
            $execution->startedAt()->format('Y-m-d H:i:s'),
            $finishedAt?->format('Y-m-d H:i:s'),
            $finishedAt !== null ? $finishedAt->getTimestamp() - $execution->startedAt()->getTimestamp() : null,
            $execution->itemsProcessed(),
            $execution->itemsCreated(),
            $execution->itemsUpdated(),
            $execution->itemsSkipped(),
            $execution->itemsWarnings(),
            $execution->itemsFailed(),
            $execution->errorMessage(),
            $execution->phase(),
            $execution->cursor(),
            $execution->page(),
            $execution->sourceId(),
            $execution->lastSuccessfulSourceId(),
            $execution->lastSuccessAt()?->format('Y-m-d H:i:s'),
            $execution->heartbeatAt()?->format('Y-m-d H:i:s'),
            $execution->resumeAvailable(),
            $execution->triggerSource(),
            $execution->stopRequested(),
            $execution->cancellationRequested(),
        );
    }
}
