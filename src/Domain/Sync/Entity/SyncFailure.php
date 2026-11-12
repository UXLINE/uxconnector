<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Entity;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncErrorCategory;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final readonly class SyncFailure
{
    public function __construct(
        public SyncExecutionId $executionId,
        public SyncJobId $syncJobId,
        public string $sourceType,
        public ?string $sourceId,
        public ?string $sourceLabel,
        public ?string $targetType,
        public ?int $targetId,
        public string $action,
        public string $status,
        public SyncErrorCategory $category,
        public ?string $errorCode,
        public string $errorMessage,
        public ?string $technicalContext,
        public ?string $sourceSnapshot,
        public int $attempt,
        public int $page,
        public ?string $cursor,
        public string $retryStatus = 'not_retried',
        public ?string $createdAt = null,
        public ?int $id = null
    ) {
    }
}
