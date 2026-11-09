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

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync;

use GlpiPlugin\Uxconnector\Application\Sync\Pagination\PaginationStrategyResolver;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ActivateSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\CollectionExtractor;
use GlpiPlugin\Uxconnector\Application\Sync\Service\CreateSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\DeleteSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\DispatchGlpiItemEventService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ExecuteSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\SyncJobExecutorInterface;
use GlpiPlugin\Uxconnector\Application\Sync\Service\LockedSyncJobExecutor;
use GlpiPlugin\Uxconnector\Application\Sync\Service\FieldValueTransformer;
use GlpiPlugin\Uxconnector\Application\Sync\Service\GetSyncJobForEditService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\GetSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\InboundRecordProcessor;
use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ListSyncExecutionsService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\RetryFailedItemsService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ResumeSyncExecutionService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\RequestStopSyncExecutionService;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\ExecutionStopRequestRepository;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ListSyncFailuresService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ListSyncJobsService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\OutboundRuntimeValueResolver;
use GlpiPlugin\Uxconnector\Application\Sync\Service\PageReferenceNormalizer;
use GlpiPlugin\Uxconnector\Application\Sync\Service\PauseSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ProcessInboundWebhookService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ProcessQueuedFailureRetriesService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\QueueSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\QueueFullReplayService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\RecoverStaleExecutionsService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ResetSyncCheckpointService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\RunActiveSyncJobsService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\RunQueuedExecutionsService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\UpdateSyncJobService;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\InteractionLookupRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\GlpiAssetReferencePostProcessor;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\GlpiRestV1LocationSearchOptionCatalog;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\GlpiRestV1TelecomLocationResourcePathResolver;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\GlpiTargetFieldsWriter;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\IndigoTelecomLocationBatchProcessor;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\LegacyLocationProfileCatalog;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\LegacyLocationProfilePathResolver;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\IndigoNetworkLocationBatchProcessor;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\IndigoMicrosoftLicenseBatchProcessor;
use GlpiPlugin\Uxconnector\Infrastructure\Shared\Event\SynchronousEventDispatcher;
use GlpiPlugin\Uxconnector\Infrastructure\Shared\Persistence\GlpiTransactionManager;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\ObjectLinkPersistenceMapper;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\ObjectLinkRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncCheckpointRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncExecutionPersistenceMapper;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncExecutionRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncFailureRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobPersistenceMapper;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Lock\MySqlExecutionMutex;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Lock\MySqlSyncWorkLock;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Writer\GlpiItemWriter;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Fields\FieldsTargetCatalog;
use GlpiPlugin\Uxconnector\Infrastructure\Template\TemplateFieldTransformProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskTicketPostProcessor;
use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskRetrySourceRecordRefresher;
use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskTopicCatalog;
use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskTopicCategoryBatchProcessor;
use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskTopicCategoryService;

/**
 * Single wiring point for the Sync bounded context's dependency
 * graph, shared by every entry point that needs it
 * (front/syncjob.php, front/syncjob.form.php, SyncJobCronTask).
 *
 * Delegates to EngineServiceProvider for the Engine-side collaborators
 * ExecuteSyncJobService needs (AuthConfigRepositoryInterface,
 * HttpEngineInterface) - this is exactly the Application-layer
 * meeting point between the two bounded contexts documented on
 * ExecuteSyncJobService's own class doc-block, made concrete here at
 * the wiring level.
 */
final class SyncServiceProvider
{
    public static function fieldsTargetCatalog(): FieldsTargetCatalog
    {
        return new FieldsTargetCatalog($GLOBALS['DB']);
    }

    public static function syncJobRepository(): SyncJobRepositoryInterface
    {
        return new SyncJobRepository(new SyncJobPersistenceMapper());
    }

    public static function interactionLookupRepository(): InteractionLookupRepositoryInterface
    {
        return new SyncJobRepository(new SyncJobPersistenceMapper());
    }

    public static function listSyncJobsService(): ListSyncJobsService
    {
        return new ListSyncJobsService(self::syncJobRepository());
    }

    public static function getSyncJobService(): GetSyncJobService
    {
        return new GetSyncJobService(self::syncJobRepository());
    }

    public static function getSyncJobForEditService(): GetSyncJobForEditService
    {
        return new GetSyncJobForEditService(
            self::syncJobRepository(),
        );
    }
    public static function updateSyncJobService(): UpdateSyncJobService
    {
        return new UpdateSyncJobService(
            self::syncJobRepository(),
            EngineServiceProvider::authConfigRepository(),
            new TargetFieldPolicy(),
            new GlpiTransactionManager($GLOBALS['DB']),
        );
    }
    public static function createSyncJobService(): CreateSyncJobService
    {
        return new CreateSyncJobService(
            self::syncJobRepository(),
            EngineServiceProvider::authConfigRepository(),
            new TargetFieldPolicy(),
            new GlpiTransactionManager($GLOBALS['DB']),
            new SynchronousEventDispatcher(),
        );
    }

    public static function objectLinkRepository(): ObjectLinkRepositoryInterface
    {
        return new ObjectLinkRepository(new ObjectLinkPersistenceMapper());
    }

    public static function syncCheckpointRepository(): \GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncCheckpointRepositoryInterface
    {
        return new SyncCheckpointRepository($GLOBALS['DB']);
    }

    public static function syncFailureRepository(): SyncFailureRepositoryInterface
    {
        return new SyncFailureRepository($GLOBALS['DB']);
    }

    public static function syncExecutionRepository(): SyncExecutionRepositoryInterface
    {
        return new SyncExecutionRepository(new SyncExecutionPersistenceMapper());
    }

    public static function listSyncExecutionsService(): ListSyncExecutionsService
    {
        return new ListSyncExecutionsService(self::syncExecutionRepository());
    }

    public static function listSyncFailuresService(): ListSyncFailuresService
    {
        return new ListSyncFailuresService(self::syncFailureRepository());
    }

    public static function stopRequestRepository(): \GlpiPlugin\Uxconnector\Domain\Sync\Repository\ExecutionStopRequestRepositoryInterface
    {
        return new ExecutionStopRequestRepository($GLOBALS['DB']);
    }

    public static function requestStopSyncExecutionService(): RequestStopSyncExecutionService
    {
        return new RequestStopSyncExecutionService(self::syncExecutionRepository(), self::stopRequestRepository());
    }

    public static function resumeSyncExecutionService(): ResumeSyncExecutionService
    {
        return new ResumeSyncExecutionService(
            self::syncExecutionRepository(),
            new GlpiTransactionManager($GLOBALS['DB']),
            new MySqlExecutionMutex($GLOBALS['DB']),
            self::stopRequestRepository(),
        );
    }

    public static function retryFailedItemsService(): RetryFailedItemsService
    {
        return new RetryFailedItemsService(self::syncFailureRepository());
    }

    public static function processQueuedFailureRetriesService(): ProcessQueuedFailureRetriesService
    {
        return new ProcessQueuedFailureRetriesService(
            self::syncFailureRepository(),
            self::syncJobRepository(),
            self::inboundRecordProcessor(),
            new ZendeskRetrySourceRecordRefresher(
                EngineServiceProvider::authConfigRepository(),
                EngineServiceProvider::httpEngine(),
            ),
            new MySqlSyncWorkLock($GLOBALS['DB']),
            self::syncExecutionRepository(),
        );
    }

    public static function executeSyncJobService(): SyncJobExecutorInterface
    {
        $objectLinks = self::objectLinkRepository();
        $authConfigs = EngineServiceProvider::authConfigRepository();
        $httpEngine = EngineServiceProvider::httpEngine();
        $jsonPathReader = new JsonPathValueReader();
        $itemWriter = new GlpiItemWriter($GLOBALS['DB'], $objectLinks);
        $transformer = new FieldValueTransformer(TemplateFieldTransformProvider::extensions());
        $topicCatalog = new ZendeskTopicCatalog($authConfigs, $httpEngine);
        $topicCategories = new ZendeskTopicCategoryService($objectLinks);
        $zendeskPostProcessor = new ZendeskTicketPostProcessor(
            $objectLinks,
            $authConfigs,
            $httpEngine,
            EngineServiceProvider::destinationPolicy(),
            $topicCatalog,
            $topicCategories,
        );
        $zendeskTopicBatchProcessor = new ZendeskTopicCategoryBatchProcessor($topicCatalog, $topicCategories);
        $glpiRestLocationCatalog = new GlpiRestV1LocationSearchOptionCatalog($httpEngine);
        $profileCatalog = new LegacyLocationProfileCatalog($httpEngine);
        $profileFieldsWriter = new GlpiTargetFieldsWriter($GLOBALS['DB']);
        $indigoNetworkProcessor = new IndigoNetworkLocationBatchProcessor(
            $GLOBALS['DB'],
            $authConfigs,
            $profileCatalog,
            $objectLinks,
            $profileFieldsWriter,
        );
        $indigoMicrosoftProcessor = new IndigoMicrosoftLicenseBatchProcessor(
            $GLOBALS['DB'],
            $authConfigs,
            $profileCatalog,
            $objectLinks,
            $profileFieldsWriter,
        );
        $indigoTelecomProcessor = new IndigoTelecomLocationBatchProcessor(
            $GLOBALS['DB'],
            $authConfigs,
            $glpiRestLocationCatalog,
            $objectLinks,
            new GlpiTargetFieldsWriter($GLOBALS['DB']),
        );

        $workLock = new MySqlSyncWorkLock($GLOBALS['DB']);
        $executor = new ExecuteSyncJobService(
            self::syncJobRepository(),
            self::syncExecutionRepository(),
            $authConfigs,
            $httpEngine,
            $itemWriter,
            $transformer,
            $jsonPathReader,
            new TargetFieldPolicy(),
            new GlpiTransactionManager($GLOBALS['DB']),
            new SynchronousEventDispatcher(),
            new CollectionExtractor($jsonPathReader),
            new PaginationStrategyResolver($jsonPathReader),
            new PageReferenceNormalizer(),
            new InboundRecordProcessor(
                $itemWriter,
                $transformer,
                $jsonPathReader,
                $objectLinks,
                [
                    $zendeskPostProcessor,
                    new GlpiAssetReferencePostProcessor($GLOBALS['DB'], $objectLinks),
                ],
                [$zendeskTopicBatchProcessor, $indigoTelecomProcessor, $indigoNetworkProcessor, $indigoMicrosoftProcessor],
                self::syncFailureRepository(),
            ),
            self::syncCheckpointRepository(),
            [new GlpiRestV1TelecomLocationResourcePathResolver($glpiRestLocationCatalog), new LegacyLocationProfilePathResolver($profileCatalog)],
            [$zendeskTopicBatchProcessor],
            self::stopRequestRepository(),
            $workLock,
        );
        return new LockedSyncJobExecutor(
            $executor,
            self::syncJobRepository(),
            $workLock,
        );
    }

    public static function queueSyncJobService(): QueueSyncJobService
    {
        return new QueueSyncJobService(
            self::syncJobRepository(),
            self::syncExecutionRepository(),
            new GlpiTransactionManager($GLOBALS['DB']),
            new MySqlExecutionMutex($GLOBALS['DB']),
        );
    }

    public static function runActiveSyncJobsService(): RunActiveSyncJobsService
    {
        return new RunActiveSyncJobsService(
            self::syncJobRepository(),
            self::executeSyncJobService(),
            EngineServiceProvider::authConfigRepository(),
            self::syncExecutionRepository(),
            self::queueSyncJobService(),
        );
    }

    public static function runQueuedExecutionsService(): RunQueuedExecutionsService
    {
        return new RunQueuedExecutionsService(
            self::syncExecutionRepository(),
            self::executeSyncJobService(),
            self::processQueuedFailureRetriesService(),
        );
    }


    public static function recoverStaleExecutionsService(): RecoverStaleExecutionsService
    {
        return new RecoverStaleExecutionsService(
            self::syncExecutionRepository(),
            self::staleExecutionMinutes(),
            self::syncJobRepository(),
            new MySqlSyncWorkLock($GLOBALS['DB']),
        );
    }

    public static function queueFullReplayService(): QueueFullReplayService
    {
        return new QueueFullReplayService(
            self::syncJobRepository(),
            self::syncExecutionRepository(),
            self::syncCheckpointRepository(),
            new GlpiTransactionManager($GLOBALS['DB']),
            new MySqlExecutionMutex($GLOBALS['DB']),
        );
    }

    public static function resetSyncCheckpointService(): ResetSyncCheckpointService
    {
        return new ResetSyncCheckpointService(
            self::syncJobRepository(),
            self::syncExecutionRepository(),
            self::syncCheckpointRepository(),
            new GlpiTransactionManager($GLOBALS['DB']),
            new MySqlExecutionMutex($GLOBALS['DB']),
        );
    }

    private static function staleExecutionMinutes(): int
    {
        $raw = getenv('UXCONNECTOR_STALE_EXECUTION_MINUTES');
        if ($raw === false || trim($raw) === '') {
            return 10;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2, 'max_range' => 1440]]);
        return $value === false ? 10 : (int) $value;
    }

    public static function deleteSyncJobService(): DeleteSyncJobService
    {
        return new DeleteSyncJobService(
            self::syncJobRepository(),
            self::syncExecutionRepository(),
            new GlpiTransactionManager($GLOBALS['DB']),
            self::syncCheckpointRepository(),
        );
    }

    public static function inboundRecordProcessor(): InboundRecordProcessor
    {
        $objectLinks = self::objectLinkRepository();
        $authConfigs = EngineServiceProvider::authConfigRepository();
        $httpEngine = EngineServiceProvider::httpEngine();
        $topicCatalog = new ZendeskTopicCatalog($authConfigs, $httpEngine);
        $topicCategories = new ZendeskTopicCategoryService($objectLinks);

        return new InboundRecordProcessor(
            new GlpiItemWriter($GLOBALS['DB'], $objectLinks),
            new FieldValueTransformer(TemplateFieldTransformProvider::extensions()),
            new JsonPathValueReader(),
            $objectLinks,
            [
                new ZendeskTicketPostProcessor(
                    $objectLinks,
                    $authConfigs,
                    $httpEngine,
                    EngineServiceProvider::destinationPolicy(),
                    $topicCatalog,
                    $topicCategories,
                ),
                new GlpiAssetReferencePostProcessor($GLOBALS['DB'], $objectLinks),
            ],
            [new ZendeskTopicCategoryBatchProcessor($topicCatalog, $topicCategories)],
            self::syncFailureRepository(),
        );
    }

    public static function processInboundWebhookService(): ProcessInboundWebhookService
    {
        $reader = new JsonPathValueReader();
        return new ProcessInboundWebhookService(
            self::interactionLookupRepository(),
            self::syncExecutionRepository(),
            EngineServiceProvider::authConfigRepository(),
            new InboundRecordProcessor(
                new GlpiItemWriter($GLOBALS['DB'], self::objectLinkRepository()),
                new FieldValueTransformer(TemplateFieldTransformProvider::extensions()),
                $reader,
            ),
            new CollectionExtractor($reader),
            new TargetFieldPolicy(),
            new GlpiTransactionManager($GLOBALS['DB']),
            new SynchronousEventDispatcher(),
        );
    }

    public static function dispatchGlpiItemEventService(): DispatchGlpiItemEventService
    {
        return new DispatchGlpiItemEventService(
            self::interactionLookupRepository(),
            EngineServiceProvider::authConfigRepository(),
            self::objectLinkRepository(),
            EngineServiceProvider::httpEngine(),
            new JsonPathValueReader(),
            new OutboundRuntimeValueResolver(),
            self::syncExecutionRepository(),
            new GlpiTransactionManager($GLOBALS['DB']),
            new SynchronousEventDispatcher(),
        );
    }

    /**
     * CYCLE D'ACTIVATION (issu des deux relectures externes).
     */
    public static function activateSyncJobService(): ActivateSyncJobService
    {
        return new ActivateSyncJobService(self::syncJobRepository(), EngineServiceProvider::authConfigRepository());
    }

    public static function pauseSyncJobService(): PauseSyncJobService
    {
        return new PauseSyncJobService(self::syncJobRepository());
    }
}
