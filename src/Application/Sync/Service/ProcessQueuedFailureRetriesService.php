<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Retry\RetrySourceRecordRefresherInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\SyncWorkLockInterface;

final class ProcessQueuedFailureRetriesService
{
    public function __construct(
        private readonly SyncFailureRepositoryInterface $failures,
        private readonly SyncJobRepositoryInterface $jobs,
        private readonly InboundRecordProcessor $processor,
        private readonly ?RetrySourceRecordRefresherInterface $sourceRefresher = null,
        private readonly ?SyncWorkLockInterface $lock = null,
        private readonly ?SyncExecutionRepositoryInterface $executions = null
    ) {
    }

    /** @return array{retried:int,succeeded:int,failed:int,warnings:int} */
    public function execute(int $limit = 50): array
    {
        $retried = $succeeded = $failed = $warnings = 0;
        foreach ($this->failures->findQueued($limit) as $failure) {
            if ($failure->id === null) {
                continue;
            }

            $job = $this->jobs->findById($failure->syncJobId);
            if ($job === null) {
                $this->failures->updateRetryStatus($failure->id, 'job_missing');
                continue;
            }

            if ($this->lock !== null && !$this->lock->acquire($job)) {
                continue;
            }
            try {
                if ($this->executions?->hasOpenExecution($failure->syncJobId)) {
                    continue;
                }
                $execution = $this->executions?->findById($failure->executionId);
                if ($execution?->status() === SyncExecutionStatus::CANCELLED || $execution?->cancellationRequested()) {
                    $this->failures->updateRetryStatus($failure->id, 'cancelled');
                    continue;
                }
                $retried++;

                try {
                    // For connectors that support targeted source reads (Zendesk in
                    // 2.18.9), retry against the *current* remote object. The
                    // historical source_snapshot remains immutable audit evidence.
                    // Unsupported connector/object types retain the previous
                    // snapshot-based behaviour for backward compatibility.
                    $record = $this->sourceRefresher?->refresh($job, $failure);
                } catch (\Throwable) {
                    // A supported live refresh that fails must never silently fall
                    // back to stale data. It remains retryable on a later pass.
                    $failed++;
                    $this->failures->updateRetryStatus($failure->id, 'source_refresh_failed');
                    continue;
                }

                if ($record === null) {
                    $record = json_decode($failure->sourceSnapshot, true);
                    if (!is_array($record)) {
                        $failed++;
                        $this->failures->updateRetryStatus($failure->id, 'snapshot_invalid');
                        continue;
                    }
                }

                try {
                    $result = $this->processor->processDetailed(
                        $job,
                        [$record],
                        $failure->executionId,
                        $failure->page,
                        $failure->cursor,
                    );
                    if ($result->failed > 0) {
                        $failed++;
                        $this->failures->updateRetryStatus($failure->id, 'failed');
                    } elseif ($result->warnings > 0) {
                        $warnings++;
                        $this->failures->updateRetryStatus($failure->id, 'warning');
                    } else {
                        $succeeded++;
                        $this->failures->updateRetryStatus($failure->id, 'succeeded');
                    }
                } catch (\Throwable) {
                    $failed++;
                    $this->failures->updateRetryStatus($failure->id, 'failed');
                }
            } finally {
                $this->lock?->release();
            }
        }

        return compact('retried', 'succeeded', 'failed', 'warnings');
    }
}
