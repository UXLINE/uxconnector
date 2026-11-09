<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\PostProcessing;

use GlpiPlugin\Uxconnector\Application\Sync\DTO\BatchProcessResult;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;

/** Optional extension: preserve warning provenance for specialized batch adapters. */
interface ExecutionAwareBatchProcessorInterface extends InboundBatchProcessorInterface
{
    /** @param list<mixed> $items */
    public function processWithContext(
        SyncJob $syncJob,
        array $items,
        ?SyncExecutionId $executionId,
        int $page,
        ?string $cursor,
        ?SyncFailureRepositoryInterface $failureRepository
    ): BatchProcessResult;
}
