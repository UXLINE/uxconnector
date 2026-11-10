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

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Pagination\PaginationStrategyResolver;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\SessionLifecycleHttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\TransientInfrastructureException;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\ExecutionStopRequestedException;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionBusyException;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionOwnershipLostException;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\SyncWorkLockInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ExecutionStopRequestRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\BuiltinTemplateTargetPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncCheckpointRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Writer\SyncItemWriterInterface;
use GlpiPlugin\Uxconnector\Application\Sync\Source\InboundResourcePathResolverInterface;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\StandaloneInboundProcessorInterface;

/**
 * Executes one INBOUND synchronization job.
 *
 * The runtime is deliberately connector-agnostic. Collection shape, nested
 * JSON paths and pagination semantics are properties of the persisted SyncJob
 * (usually produced from a JobTemplate), never hard-coded vendor knowledge.
 */
final class ExecuteSyncJobService implements SyncJobExecutorInterface
{
    private const MAX_PAGES_PER_RUN = 1000;
    private const DEFAULT_MAX_RECORDS_PER_RUN = 50000;

    private readonly CollectionExtractor $collectionExtractor;
    private readonly PaginationStrategyResolver $paginationStrategyResolver;
    private readonly PageReferenceNormalizer $pageReferenceNormalizer;
    private readonly InboundRecordProcessor $inboundRecordProcessor;

    public function __construct(
        private readonly SyncJobRepositoryInterface $syncJobRepository,
        private readonly SyncExecutionRepositoryInterface $executionRepository,
        private readonly AuthConfigRepositoryInterface $authConfigRepository,
        private readonly HttpEngineInterface $httpEngine,
        private readonly SyncItemWriterInterface $itemWriter,
        private readonly FieldValueTransformer $transformer,
        private readonly JsonPathValueReader $jsonPathReader,
        private readonly TargetFieldPolicy $targetFieldPolicy,
        private readonly TransactionManagerInterface $transaction,
        private readonly EventDispatcherInterface $events,
        ?CollectionExtractor $collectionExtractor = null,
        ?PaginationStrategyResolver $paginationStrategyResolver = null,
        ?PageReferenceNormalizer $pageReferenceNormalizer = null,
        ?InboundRecordProcessor $inboundRecordProcessor = null,
        private readonly ?SyncCheckpointRepositoryInterface $checkpointRepository = null,
        private readonly iterable $resourcePathResolvers = [],
        private readonly iterable $standaloneProcessors = [],
        private readonly ?ExecutionStopRequestRepositoryInterface $stopRequests = null,
        private readonly ?SyncWorkLockInterface $workLock = null
    ) {
        $this->collectionExtractor = $collectionExtractor ?? new CollectionExtractor($jsonPathReader);
        $this->paginationStrategyResolver = $paginationStrategyResolver ?? new PaginationStrategyResolver(
            $jsonPathReader
        );
        $this->pageReferenceNormalizer = $pageReferenceNormalizer ?? new PageReferenceNormalizer();
        $this->inboundRecordProcessor = $inboundRecordProcessor ?? new InboundRecordProcessor(
            $itemWriter,
            $transformer,
            $jsonPathReader
        );
    }

    public function execute(ExecuteSyncJobCommand $command): SyncExecution
    {
        $this->refreshExecutionTimeBudget();
        $this->workLock?->assertOwned();
        $syncJob = $this->syncJobRepository->findById($command->syncJobId);

        if ($syncJob === null) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::forId(
                $command->syncJobId
            );
        }

        if (!$syncJob->isRunnable()) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotRunnableException::forStatus(
                $syncJob->status()
            );
        }

        $syncJobId = $syncJob->id();

        if ($syncJobId === null) {
            throw new \RuntimeException(
                'Cannot execute a persisted SyncJob without an identifier.',
            );
        }

        if ($command->existingExecutionId !== null) {
            $execution = $this->executionRepository->findById($command->existingExecutionId);
            if ($execution === null || $execution->syncJobId()->value() !== $syncJobId->value()) {
                throw new \RuntimeException(
                    'Queued synchronization execution not found or does not belong to this job.'
                );
            }
            if ($execution->status() !== SyncExecutionStatus::PENDING) {
                throw new SyncExecutionBusyException('Queued execution has already been claimed or finished.');
            }
            $execution->begin();
            $execution = $this->executionRepository->save($execution);
        } else {
            $execution = $this->persistNewExecution(SyncExecution::start($syncJobId));
        }

        $authConfig = null;
        try {
            if (
                $syncJob->direction() !== \GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection::INBOUND
                 || !$syncJob->isScheduledInteraction()
            ) {
                throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncDirectionNotSupportedException
                    ::forDirection($syncJob->direction());
            }

            (new BuiltinTemplateTargetPolicy())->assertAllowed($syncJob->templateId(), $syncJob->targetItemtype());

            $this->targetFieldPolicy->assertConfiguration(
                $syncJob->targetItemtype(),
                $syncJob->matchField(),
                $syncJob->fieldMappings(),
            );

            $authConfig = $this->resolveAuthConfig($syncJob);
            $this->assertNotStopped($execution);
            // Catalog bootstraps can be executed directly without scanning tickets.
            // In particular, persisted Zendesk category jobs still store the old
            // Search Export path: do not issue any request to that endpoint.
            $standalone = null;
            foreach ($this->standaloneProcessors as $processor) {
                if ($processor instanceof StandaloneInboundProcessorInterface && $processor->supports($syncJob)) {
                    $standalone = $processor;
                    break;
                }
            }
            if ($standalone !== null) {
                $this->assertNotStopped($execution);
                $execution->heartbeat('catalog');
                $execution = $this->executionRepository->save($execution);
                $batch = $standalone->processStandalone($syncJob);
                $execution->updateProgress(
                    $batch->processed,
                    $batch->failed,
                    'catalog',
                    null,
                    1,
                    null,
                    null,
                    $batch->created,
                    $batch->updated,
                    $batch->skipped,
                    $batch->warnings,
                );
                $execution = $this->executionRepository->save($execution);
                [$itemsProcessed, $itemsFailed, $itemsCreated, $itemsUpdated, $itemsSkipped, $itemsWarnings] = [
                    $batch->processed, $batch->failed, $batch->created, $batch->updated, $batch->skipped,
                        $batch->warnings,
                ];
            } else {
                [$itemsProcessed, $itemsFailed, $itemsCreated, $itemsUpdated, $itemsSkipped,
                    $itemsWarnings] = $this->processPages($authConfig, $syncJob, $execution);
            }

            $this->assertNotStopped($execution);
            $execution->setResumePath(null);
            return $this->finishAsCompleted(
                $execution,
                $itemsProcessed,
                $itemsFailed,
                $itemsCreated,
                $itemsUpdated,
                $itemsSkipped,
                $itemsWarnings,
            );
        } catch (SyncExecutionOwnershipLostException $exception) {
            // Do not persist stale counters/checkpoints after losing ownership.
            // The watchdog may recover the abandoned execution later.
            throw $exception;
        } catch (ExecutionStopRequestedException $exception) {
            $execution->interrupt('Execution stopped by user request; resume from the last completed page.');
            return $this->saveFinishedExecution($execution);
        } catch (TransientInfrastructureException $exception) {
            $execution->interrupt($exception->getMessage());
            return $this->saveFinishedExecution($execution);
        } catch (HttpEngineException $exception) {
            if (str_starts_with($exception->getMessage(), 'HTTP transport failure:')) {
                $execution->interrupt($exception->getMessage());
                return $this->saveFinishedExecution($execution);
            }
            return $this->finishAsFailed($execution, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            // Some HTTP adapters expose exhausted transport retries as RuntimeException,
            // not HttpEngineException. Preserve the checkpoint for an explicit resume.
            if (
                preg_match(
                    '/(?:transport failure after retry budget|unknown stream error|HTTP\/2.*stream)/i',
                    $exception->getMessage()
                ) === 1
            ) {
                $execution->interrupt(
                    'Transient HTTP transport interruption; resume from the last confirmed checkpoint.'
                );
                return $this->saveFinishedExecution($execution);
            }
            return $this->finishAsFailed($execution, $exception->getMessage());
        } catch (\Throwable $exception) {
            return $this->finishAsFailed($execution, $exception->getMessage());
        } finally {
            if ($authConfig !== null && $this->httpEngine instanceof SessionLifecycleHttpEngineInterface) {
                try {
                    $this->httpEngine->closeSession($authConfig);
                } catch (\Throwable) {
                    // Closing an external session must never overwrite the business outcome.
                }
            }
        }
    }

    private function resolveAuthConfig(SyncJob $syncJob): AuthConfig
    {
        $reference = $syncJob->authConfigReference();
        $authConfig = $this->authConfigRepository->findById(new AuthConfigId($reference->value()));

        if ($authConfig === null) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigUnavailableException
                ::referenceNotFound($reference);
        }
        if (!$authConfig->isUsable()) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigUnavailableException::notUsable(
                $reference
            );
        }

        return $authConfig;
    }

    /**
     * Fetches and processes one page at a time.
     *
     * This deliberately avoids accumulating the full remote dataset in PHP
     * memory. A 10,000-ticket migration therefore has roughly the same memory
     * footprint as a 100-ticket page. For persisted cursor jobs, the checkpoint
     * advances after every fully successful page. If any page contains failed
     * records, later pages may still be processed, but the checkpoint is frozen
     * before the first failing page so the next run can safely replay it.
     *
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int}
     *         processed / failed / created / updated / skipped / warnings
     */
    private function processPages(AuthConfig $authConfig, SyncJob $syncJob, SyncExecution $execution): array
    {
        $path = $syncJob->resourcePath()->value();
        foreach ($this->resourcePathResolvers as $resolver) {
            if ($resolver instanceof InboundResourcePathResolverInterface && $resolver->supports($syncJob)) {
                $path = $resolver->resolve($authConfig, $syncJob, $path);
                break;
            }
        }
        $page = $execution->page();
        $seenPaths = [];
        $processedTotal = $execution->itemsProcessed();
        $failedTotal = $execution->itemsFailed();
        $createdTotal = $execution->itemsCreated();
        $updatedTotal = $execution->itemsUpdated();
        $skippedTotal = $execution->itemsSkipped();
        $warningsTotal = $execution->itemsWarnings();
        $checkpointBlocked = false;
        $recordsSeen = 0;
        $maxRecords = $this->maxRecordsPerRun();
        $paginationConfig = $syncJob->paginationConfig();
        $pagination = $this->paginationStrategyResolver->resolve($paginationConfig);

        $syncJobId = $syncJob->id();
        if (
            $syncJobId !== null
            && $this->checkpointRepository !== null
            && $paginationConfig->option('persist_cursor') === true
        ) {
            $storedCursor = $this->checkpointRepository->findCursor($syncJobId);
            if ($storedCursor !== null) {
                $path = $this->resumeFromCursor($path, $paginationConfig, $storedCursor);
            }
        }

        // A resumed execution starts at the beginning of the first unconfirmed
        // page, not at the first page of a potentially huge source export.
        // This reference was normalized using the existing SSRF guard.
        if ($execution->resumePath() !== null) {
            $path = $execution->resumePath();
        }

        $cursorRestarted = false;
        while (true) {
            $this->assertNotStopped($execution);
            $this->refreshExecutionTimeBudget();
            $page++;
            if ($page > self::MAX_PAGES_PER_RUN) {
                throw new \RuntimeException(sprintf(
                    'Pagination safety limit of %d pages exceeded for "%s".',
                    self::MAX_PAGES_PER_RUN,
                    $syncJob->resourcePath()->value(),
                ));
            }

            if (isset($seenPaths[$path])) {
                throw new \RuntimeException('Pagination loop detected for the current resource.');
            }
            $seenPaths[$path] = true;

            // Persist a lease heartbeat before each potentially slow remote page.
            // If the process/container dies afterwards, the independent watchdog
            // can reliably distinguish an abandoned execution from fresh work.
            $execution->heartbeat('fetching');
            if ($execution->resumePath() === null) {
                $execution->setResumePath($path);
            }
            $execution = $this->executionRepository->save($execution);

            $response = $this->httpEngine->execute($authConfig, new HttpRequest(HttpMethod::GET, $path));
            if (!$response->isSuccessful()) {
                $freshPath = \GlpiPlugin\Uxconnector\Domain\Sync\Policy\RejectedCursorPolicy::restartPath(
                    $syncJob,
                    $path,
                    $response->statusCode
                );
                if ($freshPath !== null && !$cursorRestarted) {
                    $cursorRestarted = true;
                    $path = $freshPath;
                    $seenPaths = [];
                    $execution->setResumePath($path);
                    $execution = $this->executionRepository->save($execution);
                    // Replayed records reconcile through ObjectLink. The restart
                    // is bounded to one; another 422 fails with a safe diagnostic.
                    continue;
                }
                $message = sprintf(
                    'External system returned HTTP %d for resource "%s".',
                    $response->statusCode,
                    parse_url($path, PHP_URL_PATH) ?: '/'
                );
                if ($freshPath !== null) {
                    $message .= ' Cursor rejected again after one export restart. Check export duration and query '
                        . 'before starting a new run.';
                }
                if ($response->statusCode === 408 || $response->statusCode === 429 || $response->statusCode >= 500) {
                    throw new TransientInfrastructureException($message);
                }
                throw new \RuntimeException($message);
            }

            $decoded = $this->decodeResponseObject($response);
            $pageItems = $this->collectionExtractor->extract($decoded, $syncJob->collectionPath());
            $recordsSeen += count($pageItems);
            if ($maxRecords > 0 && $recordsSeen > $maxRecords) {
                throw new \RuntimeException(sprintf(
                    'Record safety limit of %d items exceeded for "%s". '
                    . 'Set UXCONNECTOR_MAX_RECORDS_PER_RUN to a higher value for an intentional large migration.',
                    $maxRecords,
                    $syncJob->resourcePath()->value(),
                ));
            }
            $batchResult = $this->inboundRecordProcessor->processDetailed(
                $syncJob,
                $pageItems,
                $execution->id(),
                $page,
                $execution->cursor(),
                function () use ($execution): void {
                    $this->assertNotStopped($execution);
                    // Long Zendesk pages can exceed the watchdog's lease.
                    // Refresh heartbeat at every record boundary, not just pages.
                    $execution->heartbeat('processing');
                    $this->executionRepository->save($execution);
                },
            );
            $pageProcessed = $batchResult->processed;
            $pageFailed = $batchResult->failed;
            $processedTotal += $pageProcessed;
            $failedTotal += $pageFailed;
            $createdTotal += $batchResult->created;
            $updatedTotal += $batchResult->updated;
            $skippedTotal += $batchResult->skipped;
            $warningsTotal += $batchResult->warnings;

            $candidateCursor = null;
            $cursorPath = $paginationConfig->option('cursor_response_path');
            if (is_string($cursorPath) && $cursorPath !== '') {
                $rawCursor = $this->jsonPathReader->read($decoded, $cursorPath);
                if (is_scalar($rawCursor) && (string) $rawCursor !== '') {
                    $candidateCursor = (string) $rawCursor;
                }
            }

            if ($pageFailed > 0) {
                $checkpointBlocked = true;
            }

            if (
                !$checkpointBlocked
                && $candidateCursor !== null
                && $syncJobId !== null
                && $this->checkpointRepository !== null
                && $paginationConfig->option('persist_cursor') === true
            ) {
                $this->checkpointRepository->saveCursor($syncJobId, $candidateCursor);
            }

            $lastSourceId = null;
            if ($pageItems !== []) {
                $lastItem = $pageItems[array_key_last($pageItems)];
                if (is_array($lastItem)) {
                    $rawLastId = $this->jsonPathReader->read($lastItem, $syncJob->externalIdField()->value());
                    if (is_scalar($rawLastId)) {
                        $lastSourceId = (string) $rawLastId;
                    }
                }
            }
            // Search Export can supply a next link even when the export is exhausted.
            $searchExportFinished = $syncJob->templateId() === 'zendesk.tickets.nonclosed.inbound'
                && in_array(
                    parse_url($path, PHP_URL_PATH),
                    ['/api/v2/search/export.json', '/api/v2/search/export'],
                    true
                )
                && in_array($this->jsonPathReader->read($decoded, 'meta.has_more'), [false, 'false'], true);
            $nextReference = $searchExportFinished
                ? null
                : $pagination->nextPageReference($path, $decoded, count($pageItems));
            $nextPath = $nextReference === null ? null : $this->pageReferenceNormalizer->normalize(
                $authConfig,
                $nextReference
            );
            if (!$checkpointBlocked) {
                $execution->setResumePath($nextPath);
            }
            $execution->updateProgress(
                $processedTotal,
                $failedTotal,
                'processing',
                $candidateCursor,
                $page,
                $lastSourceId,
                $pageFailed === 0 ? $lastSourceId : null,
                $createdTotal,
                $updatedTotal,
                $skippedTotal,
                $warningsTotal,
            );
            $execution = $this->executionRepository->save($execution);

            if ($nextPath === null) {
                break;
            }

            $path = $nextPath;
        }

        $this->refreshExecutionTimeBudget();
        return [$processedTotal, $failedTotal, $createdTotal, $updatedTotal, $skippedTotal, $warningsTotal];
    }


    private function assertNotStopped(SyncExecution $execution): void
    {
        $this->workLock?->assertOwned();
        $id = $execution->id();
        if ($id !== null && $this->stopRequests?->isStopRequested($id)) {
            throw new ExecutionStopRequestedException('Stop requested by user.');
        }
    }

    private function maxRecordsPerRun(): int
    {
        $raw = getenv('UXCONNECTOR_MAX_RECORDS_PER_RUN');
        if ($raw === false || trim($raw) === '') {
            return self::DEFAULT_MAX_RECORDS_PER_RUN;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000000]]);

        return $value === false ? self::DEFAULT_MAX_RECORDS_PER_RUN : (int) $value;
    }

    /**
     * Long-running inbound jobs are deliberately processed page by page.
     * Reset PHP's execution timer at each page boundary so a healthy batch
     * is not killed only because the whole synchronization exceeds the web
     * request's default max_execution_time. This does not make a single slow
     * page unlimited; it merely gives each page a fresh normal PHP budget.
     */
    private function refreshExecutionTimeBudget(): void
    {
        if (!function_exists('set_time_limit')) {
            return;
        }

        $configured = (int) ini_get('max_execution_time');
        if ($configured <= 0) {
            return;
        }

        @set_time_limit($configured);
    }

    private function resumeFromCursor(
        string $path,
        \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig $config,
        string $cursor
    ): string {
        $parts = parse_url($path);
        if ($parts === false) {
            throw new \RuntimeException('Unable to parse synchronization resource path.');
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $removeParameter = $config->option('initial_parameter_to_remove_on_resume');
        if (is_string($removeParameter) && $removeParameter !== '') {
            unset($query[$removeParameter]);
        }

        $cursorParameter = $config->option('cursor_request_parameter');
        if (!is_string($cursorParameter) || $cursorParameter === '') {
            throw new \RuntimeException('Persisted cursor requires cursor_request_parameter.');
        }
        $query[$cursorParameter] = $cursor;

        $rebuilt = (string) ($parts['path'] ?? '');
        if ($rebuilt === '') {
            $rebuilt = '/';
        }
        $rebuilt .= '?' . http_build_query($query);

        return $rebuilt;
    }

    /** @return array<mixed> */
    private function decodeResponseObject(HttpResponse $response): array
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('External response body is not a JSON array/object.');
        }
        return $decoded;
    }

    private function persistNewExecution(SyncExecution $execution): SyncExecution
    {
        $this->transaction->begin();

        try {
            $execution = $this->executionRepository->save($execution);
            $this->transaction->commit();

            return $execution;
        } catch (\Throwable $exception) {
            $this->safeRollback();
            throw $exception;
        }
    }

    private function finishAsCompleted(
        SyncExecution $execution,
        int $itemsProcessed,
        int $itemsFailed,
        int $itemsCreated = 0,
        int $itemsUpdated = 0,
        int $itemsSkipped = 0,
        int $itemsWarnings = 0
    ): SyncExecution {
        $execution->completeDetailed(
            $itemsProcessed,
            $itemsFailed,
            $itemsCreated,
            $itemsUpdated,
            $itemsSkipped,
            $itemsWarnings,
        );
        return $this->saveFinishedExecution($execution);
    }

    private function finishAsFailed(SyncExecution $execution, string $errorMessage): SyncExecution
    {
        $execution->fail($errorMessage);
        return $this->saveFinishedExecution($execution);
    }

    private function saveFinishedExecution(SyncExecution $execution): SyncExecution
    {
        $this->transaction->begin();

        try {
            $saved = $this->executionRepository->save($execution);
            $this->transaction->commit();
        } catch (\Throwable $exception) {
            $this->safeRollback();
            throw $exception;
        }

        foreach ($execution->releaseEvents() as $event) {
            $this->events->dispatch($event);
        }

        return $saved;
    }

    /** Never let a lost DB connection mask the original synchronization error. */
    private function safeRollback(): void
    {
        try {
            $this->transaction->rollback();
        } catch (\Throwable) {
            // The worker recovery pass will reconcile stale RUNNING rows once DB is back.
        }
    }
}
