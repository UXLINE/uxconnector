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
        private readonly ?SyncCheckpointRepositoryInterface $checkpointRepository = null
    ) {
        $this->collectionExtractor = $collectionExtractor ?? new CollectionExtractor($jsonPathReader);
        $this->paginationStrategyResolver = $paginationStrategyResolver ?? new PaginationStrategyResolver($jsonPathReader);
        $this->pageReferenceNormalizer = $pageReferenceNormalizer ?? new PageReferenceNormalizer();
        $this->inboundRecordProcessor = $inboundRecordProcessor ?? new InboundRecordProcessor($itemWriter, $transformer, $jsonPathReader);
    }

    public function execute(ExecuteSyncJobCommand $command): SyncExecution
    {
        $this->refreshExecutionTimeBudget();
        $syncJob = $this->syncJobRepository->findById($command->syncJobId);

        if ($syncJob === null) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::forId($command->syncJobId);
        }

        if (!$syncJob->isRunnable()) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotRunnableException::forStatus($syncJob->status());
        }

        $syncJobId = $syncJob->id();

        if ($syncJobId === null) {
            throw new \RuntimeException(
                'Cannot execute a persisted SyncJob without an identifier.'
            );
        }

        $execution = $this->persistNewExecution(
            SyncExecution::start($syncJobId)
        );

        try {
            if ($syncJob->direction() !== \GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection::INBOUND
                 || !$syncJob->isScheduledInteraction()) {
                throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncDirectionNotSupportedException::forDirection($syncJob->direction());
            }

            (new BuiltinTemplateTargetPolicy())->assertAllowed($syncJob->templateId(), $syncJob->targetItemtype());

            $this->targetFieldPolicy->assertConfiguration(
                $syncJob->targetItemtype(),
                $syncJob->matchField(),
                $syncJob->fieldMappings()
            );

            $authConfig = $this->resolveAuthConfig($syncJob);
            [$itemsProcessed, $itemsFailed] = $this->processPages($authConfig, $syncJob);

            return $this->finishAsCompleted($execution, $itemsProcessed, $itemsFailed);
        } catch (\Throwable $exception) {
            return $this->finishAsFailed($execution, $exception->getMessage());
        }
    }

    private function resolveAuthConfig(SyncJob $syncJob): AuthConfig
    {
        $reference = $syncJob->authConfigReference();
        $authConfig = $this->authConfigRepository->findById(new AuthConfigId($reference->value()));

        if ($authConfig === null) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigUnavailableException::referenceNotFound($reference);
        }
        if (!$authConfig->isUsable()) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigUnavailableException::notUsable($reference);
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
     * @return array{0:int,1:int} processed / failed
     */
    private function processPages(AuthConfig $authConfig, SyncJob $syncJob): array
    {
        $path = $syncJob->resourcePath()->value();
        $page = 0;
        $seenPaths = [];
        $processedTotal = 0;
        $failedTotal = 0;
        $checkpointBlocked = false;
        $recordsSeen = 0;
        $maxRecords = $this->maxRecordsPerRun();
        $paginationConfig = $syncJob->paginationConfig();
        $pagination = $this->paginationStrategyResolver->resolve($paginationConfig);

        $syncJobId = $syncJob->id();
        if ($syncJobId !== null
            && $this->checkpointRepository !== null
            && $paginationConfig->option('persist_cursor') === true
        ) {
            $storedCursor = $this->checkpointRepository->findCursor($syncJobId);
            if ($storedCursor !== null) {
                $path = $this->resumeFromCursor($path, $paginationConfig, $storedCursor);
            }
        }

        while (true) {
            $this->refreshExecutionTimeBudget();
            $page++;
            if ($page > self::MAX_PAGES_PER_RUN) {
                throw new \RuntimeException(sprintf(
                    'Pagination safety limit of %d pages exceeded for "%s".',
                    self::MAX_PAGES_PER_RUN,
                    $syncJob->resourcePath()->value()
                ));
            }

            if (isset($seenPaths[$path])) {
                throw new \RuntimeException(sprintf('Pagination loop detected for "%s".', $path));
            }
            $seenPaths[$path] = true;

            $response = $this->httpEngine->execute($authConfig, new HttpRequest(HttpMethod::GET, $path));
            if (!$response->isSuccessful()) {
                throw new \RuntimeException(sprintf(
                    'External system returned HTTP %d for "%s".',
                    $response->statusCode,
                    $path
                ));
            }

            $decoded = $this->decodeResponseObject($response);
            $pageItems = $this->collectionExtractor->extract($decoded, $syncJob->collectionPath());
            $recordsSeen += count($pageItems);
            if ($maxRecords > 0 && $recordsSeen > $maxRecords) {
                throw new \RuntimeException(sprintf(
                    'Record safety limit of %d items exceeded for "%s". '
                    . 'Set UXCONNECTOR_MAX_RECORDS_PER_RUN to a higher value for an intentional large migration.',
                    $maxRecords,
                    $syncJob->resourcePath()->value()
                ));
            }
            [$pageProcessed, $pageFailed] = $this->inboundRecordProcessor->process($syncJob, $pageItems);
            $processedTotal += $pageProcessed;
            $failedTotal += $pageFailed;

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

            if (!$checkpointBlocked
                && $candidateCursor !== null
                && $syncJobId !== null
                && $this->checkpointRepository !== null
                && $paginationConfig->option('persist_cursor') === true
            ) {
                $this->checkpointRepository->saveCursor($syncJobId, $candidateCursor);
            }

            $nextReference = $pagination->nextPageReference($path, $decoded, count($pageItems));
            if ($nextReference === null) {
                break;
            }

            $path = $this->pageReferenceNormalizer->normalize($authConfig, $nextReference);
        }

        $this->refreshExecutionTimeBudget();
        return [$processedTotal, $failedTotal];
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
            $this->transaction->rollback();
            throw $exception;
        }
    }

    private function finishAsCompleted(SyncExecution $execution, int $itemsProcessed, int $itemsFailed): SyncExecution
    {
        $execution->complete($itemsProcessed, $itemsFailed);
        return $this->saveFinishedExecution($execution);
    }

    private function finishAsFailed(SyncExecution $execution, string $errorMessage): SyncExecution
    {
        $execution->fail($errorMessage);
        return $this->saveFinishedExecution($execution);
    }

    private function saveFinishedExecution(SyncExecution $execution): SyncExecution
    {
        $events = $execution->releaseEvents();

        $this->transaction->begin();

        try {
            $execution = $this->executionRepository->save($execution);
            $this->transaction->commit();
        } catch (\Throwable $exception) {
            $this->transaction->rollback();
            throw $exception;
        }

        foreach ($events as $event) {
            $this->events->dispatch($event);
        }

        return $execution;
    }
}
