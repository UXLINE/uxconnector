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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ExecuteSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\FieldValueTransformer;
use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ReconciliationContext;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use GlpiPlugin\Uxconnector\Domain\Sync\Writer\SyncItemWriterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExecuteSyncJobService::class)]
final class ExecuteSyncJobServiceTest extends TestCase
{
   private function makeActiveSyncJob(?CollectionPath $collectionPath = null, ?PaginationConfig $paginationConfig = null): SyncJob {
       $job = SyncJob::create(
           new SyncJobName('MDM inventory sync'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [
               new FieldMapping('serialNumber', 'serial', required: true),
               new FieldMapping('contactName', 'contact', required: false),
           ],
           null,
           $collectionPath,
           $paginationConfig
       );
       $job->assignId(new SyncJobId(1));
       $job->activate();
       $job->releaseEvents();

       return $job;
   }

   private function makeUsableAuthConfig(): AuthConfig {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Local Mock'),
           new Endpoint('https://api.example.com'),
           AuthMethod::NONE
       );
       $authConfig->assignId(new AuthConfigId(1));
       $authConfig->markTesting();
       $authConfig->activate();

       return $authConfig;
   }

   private function makeService(
        FakeSyncJobRepository $syncJobRepository,
        FakeAuthConfigRepository $authConfigRepository,
        FakeHttpEngine $httpEngine,
        SyncItemWriterInterface $itemWriter,
        FakeSyncExecutionRepository $executionRepository,
        ExecuteSyncJobRecordingTransactionManager $transaction,
        ExecuteSyncJobRecordingEventDispatcher $events
    ): ExecuteSyncJobService {
       return new ExecuteSyncJobService(
           $syncJobRepository,
           $executionRepository,
           $authConfigRepository,
           $httpEngine,
           $itemWriter,
           new FieldValueTransformer(),
           new JsonPathValueReader(),
           new TargetFieldPolicy(),
           $transaction,
           $events
       );
   }

   public function testHappyPathCompletesSuccessfully(): void {
       $job = $this->makeActiveSyncJob();
       $authConfig = $this->makeUsableAuthConfig();

       $syncJobRepo = new FakeSyncJobRepository([$job]);
       $authConfigRepo = new FakeAuthConfigRepository([$authConfig]);
       $httpEngine = new FakeHttpEngine(new HttpResponse(200, [], json_encode([
           ['id' => 'ext-1', 'serialNumber' => 'SN-1', 'contactName' => 'Alice'],
           ['id' => 'ext-2', 'serialNumber' => 'SN-2', 'contactName' => 'Bob'],
       ])));
       $writer = new FakeSyncItemWriter();
       $executionRepo = new FakeSyncExecutionRepository();
       $transaction = new ExecuteSyncJobRecordingTransactionManager();
       $events = new ExecuteSyncJobRecordingEventDispatcher();

       $service = $this->makeService($syncJobRepo, $authConfigRepo, $httpEngine, $writer, $executionRepo, $transaction, $events);

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame('success', $execution->status()->value);
       self::assertSame(2, $execution->itemsProcessed());
       self::assertSame(0, $execution->itemsFailed());
       self::assertCount(2, $writer->written);
       self::assertSame('SN-1', $writer->written[0]['serial']);
       self::assertSame('Alice', $writer->written[0]['contact']);
       self::assertCount(1, $events->dispatched);
   }

   public function testMissingRequiredFieldOnOneItemYieldsPartialSuccess(): void {
       $job = $this->makeActiveSyncJob();
       $authConfig = $this->makeUsableAuthConfig();

       $syncJobRepo = new FakeSyncJobRepository([$job]);
       $authConfigRepo = new FakeAuthConfigRepository([$authConfig]);
       $httpEngine = new FakeHttpEngine(new HttpResponse(200, [], json_encode([
           ['id' => 'ext-1', 'serialNumber' => 'SN-1'],
           ['id' => 'ext-2', 'contactName' => 'No serial'], // missing required serialNumber
       ])));
       $writer = new FakeSyncItemWriter();
       $executionRepo = new FakeSyncExecutionRepository();

       $service = $this->makeService(
           $syncJobRepo,
           $authConfigRepo,
           $httpEngine,
           $writer,
           $executionRepo,
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame('partial', $execution->status()->value);
       self::assertSame(1, $execution->itemsProcessed());
       self::assertSame(1, $execution->itemsFailed());
   }

   public function testResponseWrappedInValueKeyIsExtracted(): void {
       $job = $this->makeActiveSyncJob(new CollectionPath('value'));
       $authConfig = $this->makeUsableAuthConfig();

       $httpEngine = new FakeHttpEngine(new HttpResponse(200, [], json_encode([
           'value' => [
               ['id' => 'ext-1', 'serialNumber' => 'SN-1', 'contactName' => 'Alice'],
           ],
       ])));

       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([$authConfig]),
           $httpEngine,
           new FakeSyncItemWriter(),
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame(1, $execution->itemsProcessed());
   }

   public function testNestedJsonFieldsAndConfiguredCollectionAreMapped(): void {
       $job = SyncJob::create(
           new SyncJobName('Nested API records'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/records'),
           TargetItemtype::TICKET,
           new MatchField('externalid'),
           new ExternalIdField('id'),
           [
               new FieldMapping('key', 'externalid', required: true),
               new FieldMapping('fields.summary', 'name', required: true),
               new FieldMapping('@literal:1', 'type', required: true, transform: 'integer'),
           ],
           null,
           new CollectionPath('records')
       );
       $job->assignId(new SyncJobId(1));
       $job->activate();
       $job->releaseEvents();

       $writer = new FakeSyncItemWriter();
       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([$this->makeUsableAuthConfig()]),
           new FakeHttpEngine(new HttpResponse(200, [], json_encode([
               'isLast' => true,
               'records' => [[
                   'id' => '10001',
                   'key' => 'KAN-1',
                   'fields' => ['summary' => 'Nested record'],
               ]],
           ]))),
           $writer,
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame('success', $execution->status()->value);
       self::assertSame('KAN-1', $writer->written[0]['externalid']);
       self::assertSame('Nested record', $writer->written[0]['name']);
       self::assertSame(1, $writer->written[0]['type']);
   }

   public function testConfiguredCursorPaginationIsFollowed(): void {
       $job = $this->makeActiveSyncJob(
           new CollectionPath('records'),
           PaginationConfig::fromArray('cursor', [
               'cursor_response_path' => 'cursor.next',
               'cursor_request_parameter' => 'cursor',
               'last_page_response_path' => 'cursor.isLast',
           ])
       );
       $engine = new FakeHttpEngine([
           new HttpResponse(200, [], json_encode([
               'cursor' => ['isLast' => false, 'next' => 'abc+123'],
               'records' => [['id' => '1', 'serialNumber' => 'SN-1']],
           ])),
           new HttpResponse(200, [], json_encode([
               'cursor' => ['isLast' => true],
               'records' => [['id' => '2', 'serialNumber' => 'SN-2']],
           ])),
       ]);

       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([$this->makeUsableAuthConfig()]),
           $engine,
           new FakeSyncItemWriter(),
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame(2, $execution->itemsProcessed());
       self::assertSame(2, $engine->calls);
       self::assertStringContainsString('cursor=abc%2B123', $engine->requestedPaths[1]);
   }

   public function testConfiguredOffsetPaginationIsFollowed(): void {
       $job = $this->makeActiveSyncJob(
           new CollectionPath('payload.records'),
           PaginationConfig::fromArray('offset', [
               'offset_response_path' => 'page.offset',
               'offset_request_parameter' => 'offset',
               'page_size_response_path' => 'page.limit',
               'total_response_path' => 'page.total',
               'last_page_response_path' => 'page.last',
           ])
       );
       $engine = new FakeHttpEngine([
           new HttpResponse(200, [], json_encode([
               'page' => ['last' => false, 'offset' => 0, 'limit' => 1, 'total' => 2],
               'payload' => ['records' => [['id' => '1', 'serialNumber' => 'SN-1']]],
           ])),
           new HttpResponse(200, [], json_encode([
               'page' => ['last' => true, 'offset' => 1, 'limit' => 1, 'total' => 2],
               'payload' => ['records' => [['id' => '2', 'serialNumber' => 'SN-2']]],
           ])),
       ]);

       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([$this->makeUsableAuthConfig()]),
           $engine,
           new FakeSyncItemWriter(),
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame(2, $execution->itemsProcessed());
       self::assertSame(2, $engine->calls);
       self::assertStringContainsString('offset=1', $engine->requestedPaths[1]);
   }

   public function testSyncJobNotFoundThrows(): void {
       $service = $this->makeService(
           new FakeSyncJobRepository([]),
           new FakeAuthConfigRepository([]),
           new FakeHttpEngine(new HttpResponse(200, [], '[]')),
           new FakeSyncItemWriter(),
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::class);

       $service->execute(new ExecuteSyncJobCommand(new SyncJobId(999)));
   }

   public function testDraftSyncJobIsNotRunnable(): void {
       $draftJob = SyncJob::create(
           new SyncJobName('Draft job'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id')
       );
       $draftJob->assignId(new SyncJobId(2));

       $service = $this->makeService(
           new FakeSyncJobRepository([$draftJob]),
           new FakeAuthConfigRepository([]),
           new FakeHttpEngine(new HttpResponse(200, [], '[]')),
           new FakeSyncItemWriter(),
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotRunnableException::class);

       $service->execute(new ExecuteSyncJobCommand(new SyncJobId(2)));
   }

   public function testMissingAuthConfigResultsInFailedExecutionNotAnException(): void {
       $job = $this->makeActiveSyncJob();

       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([]), // no AuthConfig #1 registered
           new FakeHttpEngine(new HttpResponse(200, [], '[]')),
           new FakeSyncItemWriter(),
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame('failed', $execution->status()->value);
       self::assertStringContainsString('does not exist', $execution->errorMessage());
   }

   public function testNonSuccessfulHttpResponseResultsInFailedExecution(): void {
       $job = $this->makeActiveSyncJob();
       $authConfig = $this->makeUsableAuthConfig();

       $httpEngine = new FakeHttpEngine(new HttpResponse(503, [], '{"error":"unavailable"}'));

       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([$authConfig]),
           $httpEngine,
           new FakeSyncItemWriter(),
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame('failed', $execution->status()->value);
       self::assertStringContainsString('503', $execution->errorMessage());
   }

   public function testWriterRejectingAnItemCountsAsFailed(): void {
       $job = $this->makeActiveSyncJob();
       $authConfig = $this->makeUsableAuthConfig();

       $httpEngine = new FakeHttpEngine(new HttpResponse(200, [], json_encode([
           ['serialNumber' => 'SN-1', 'contactName' => 'Alice'],
       ])));
       $writer = new FakeSyncItemWriter(alwaysSucceeds: false);

       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([$authConfig]),
           $httpEngine,
           $writer,
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame('failed', $execution->status()->value);
       self::assertSame(0, $execution->itemsProcessed());
       self::assertSame(1, $execution->itemsFailed());
   }

    /**
     * CORRECTIF P0 (revue de code externe) : défense en profondeur —
     * un SyncJob dont la direction n'est pas INBOUND (créé avant ce
     * correctif, ou modifié directement en base) doit être refusé à
     * l'exécution, et non silencieusement traité comme INBOUND.
     */
   public function testSyncJobWithAnUnimplementedDirectionResultsInFailedExecution(): void {
        /*
         * Simulates an inconsistent legacy row already stored before
         * outbound validation was introduced. Reconstitution deliberately
         * bypasses activation invariants.
         */
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        $job = SyncJob::reconstitute(
            new SyncJobId(1),
            new SyncJobName('Legacy outbound job'),
            new AuthConfigReference(1),
            SyncDirection::OUTBOUND,
            new ResourcePath('/computers'),
            TargetItemtype::COMPUTER,
            new MatchField('serial'),
            new ExternalIdField('id'),
            [
                new FieldMapping(
                    'serialNumber',
                    'serial',
                    required: true
                ),
            ],
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus::ACTIVE,
            $now,
            $now
        );

        $httpEngine = new FakeHttpEngine(
            new HttpResponse(200, [], '[]')
        );

        $service = $this->makeService(
            new FakeSyncJobRepository([$job]),
            new FakeAuthConfigRepository([$this->makeUsableAuthConfig()]),
            $httpEngine,
            new FakeSyncItemWriter(),
            new FakeSyncExecutionRepository(),
            new ExecuteSyncJobRecordingTransactionManager(),
            new ExecuteSyncJobRecordingEventDispatcher()
        );

        $execution = $service->execute(
            new ExecuteSyncJobCommand(new SyncJobId(1))
        );

        self::assertSame('failed', $execution->status()->value);
        self::assertStringContainsString(
            'outbound',
            strtolower((string) $execution->errorMessage())
        );
        self::assertSame(
            0,
            $httpEngine->calls,
            'The external system must never be called for this legacy job.'
        );
}

    /**
     * CORRECTIF P0 (revue de code externe) : reproduit exactement le
     * scénario signalé — une exception dans la transformation d'un
     * seul élément au milieu d'un lot ne doit ni interrompre le lot,
     * ni faire perdre le décompte des éléments déjà écrits avec
     * succès.
     */
   public function testATransformFailureOnOneItemDoesNotAbortTheRestOfTheBatch(): void {
       $job = SyncJob::create(
           new SyncJobName('Batch with one bad date'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [
               new FieldMapping('serialNumber', 'serial', required: true),
               new FieldMapping('purchaseDate', 'last_boot', required: false, transform: 'date_iso8601'),
           ]
       );
       $job->assignId(new SyncJobId(1));
       $job->activate();
       $job->releaseEvents();

       $items = [
           ['id' => 'ext-1', 'serialNumber' => 'SN-1', 'purchaseDate' => '2026-01-15T10:00:00+00:00'],
           ['id' => 'ext-2', 'serialNumber' => 'SN-2', 'purchaseDate' => 'not-a-real-date'], // will throw
           ['id' => 'ext-3', 'serialNumber' => 'SN-3', 'purchaseDate' => '2026-02-01T09:00:00+00:00'],
       ];
       $httpEngine = new FakeHttpEngine(new HttpResponse(200, [], json_encode($items)));
       $writer = new FakeSyncItemWriter();

       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([$this->makeUsableAuthConfig()]),
           $httpEngine,
           $writer,
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame('partial', $execution->status()->value);
       self::assertSame(2, $execution->itemsProcessed(), 'items 1 and 3 must have been written despite item 2 failing');
       self::assertSame(1, $execution->itemsFailed());
       self::assertCount(2, $writer->written, 'the writer must have actually been called for the 2 valid items');
   }

    /**
     * Same guarantee, but for a failure inside the writer itself
     * rather than inside field mapping — both call sites of
     * applyFieldMappings() are individually protected.
     */
   public function testAWriterExceptionOnOneItemDoesNotAbortTheRestOfTheBatch(): void {
       $job = $this->makeActiveSyncJob();

       $items = [
           ['id' => 'ext-1', 'serialNumber' => 'SN-1', 'contactName' => 'Alice'],
           ['id' => 'ext-2', 'serialNumber' => 'SN-2', 'contactName' => 'Bob'],
           ['id' => 'ext-3', 'serialNumber' => 'SN-3', 'mail' => 'c@example.com'],
       ];
       $httpEngine = new FakeHttpEngine(new HttpResponse(200, [], json_encode($items)));
       $writer = new ThrowingOnceSyncItemWriter(failOnSerial: 'SN-2');

       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([$this->makeUsableAuthConfig()]),
           $httpEngine,
           $writer,
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame('partial', $execution->status()->value);
       self::assertSame(2, $execution->itemsProcessed());
       self::assertSame(1, $execution->itemsFailed());
   }


   /**
    * Volume regression: 10,000 records split across 100 pages must be
    * processed successfully. This exercises the same page-at-a-time loop used
    * by Zendesk Search Export without requiring 10,000 real Zendesk tickets.
    */
   public function testTenThousandRecordsAreProcessedAcrossOneHundredPages(): void {
       $job = $this->makeActiveSyncJob(
           new CollectionPath('results'),
           PaginationConfig::fromArray('next_link', [
               'next_link_response_path' => 'links.next',
           ])
       );
       $authConfig = $this->makeUsableAuthConfig();

       $responses = [];
       for ($page = 1; $page <= 100; $page++) {
           $items = [];
           for ($offset = 1; $offset <= 100; $offset++) {
               $id = (($page - 1) * 100) + $offset;
               $items[] = [
                   'id' => 'ext-' . $id,
                   'serialNumber' => 'SN-' . $id,
                   'contactName' => 'Synthetic user ' . $id,
               ];
           }

           $responses[] = new HttpResponse(200, [], json_encode([
               'results' => $items,
               'links' => [
                   'next' => $page < 100 ? '/computers?page=' . ($page + 1) : null,
               ],
           ], JSON_THROW_ON_ERROR));
       }

       $engine = new FakeHttpEngine($responses);
       $writer = new CountingSyncItemWriter();
       $service = $this->makeService(
           new FakeSyncJobRepository([$job]),
           new FakeAuthConfigRepository([$authConfig]),
           $engine,
           $writer,
           new FakeSyncExecutionRepository(),
           new ExecuteSyncJobRecordingTransactionManager(),
           new ExecuteSyncJobRecordingEventDispatcher()
       );

       $execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));

       self::assertSame('success', $execution->status()->value);
       self::assertSame(10000, $execution->itemsProcessed());
       self::assertSame(0, $execution->itemsFailed());
       self::assertSame(10000, $writer->count);
       self::assertSame(100, $engine->calls);
   }
}

/**
 * @internal test double — throws instead of returning false for one
 * specific serial, to simulate GlpiItemWriter raising an unexpected
 * exception (e.g. a GLPI-side DB error) partway through a batch.
 */
final class ThrowingOnceSyncItemWriter implements SyncItemWriterInterface
{
   public function __construct(
        private readonly string $failOnSerial
    ) {
   }

   public function write(ReconciliationContext $context, array $mappedFields): bool {
      if (($mappedFields['serial'] ?? null) === $this->failOnSerial) {
          throw new \RuntimeException('simulated GLPI-side write failure');
      }

       return true;
   }
}

/**
 * @internal test double
 */
final class FakeSyncJobRepository implements SyncJobRepositoryInterface
{
    /**
     * @param list<SyncJob> $items
     */
   public function __construct(private readonly array $items) {
   }

   public function save(SyncJob $syncJob): SyncJob {
       return $syncJob;
   }

   public function findById(SyncJobId $id): ?SyncJob {
      foreach ($this->items as $item) {
         if ($item->id()?->equals($id)) {
            return $item;
         }
      }

       return null;
   }

   public function findAll(): array {
       return $this->items;
   }

   public function findRunnable(): array {
       return array_values(array_filter($this->items, fn (SyncJob $job) => $job->isRunnable()));
   }

   public function delete(SyncJobId $id): void {
   }

   public function exists(SyncJobId $id): bool {
       return $this->findById($id) !== null;
   }
}

/**
 * @internal test double
 */
final class FakeAuthConfigRepository implements AuthConfigRepositoryInterface
{
    /**
     * @param list<AuthConfig> $items
     */
   public function __construct(private readonly array $items) {
   }

   public function save(AuthConfig $authConfig): AuthConfig {
       return $authConfig;
   }

   public function findById(AuthConfigId $id): ?AuthConfig {
      foreach ($this->items as $item) {
         if ($item->id()?->equals($id)) {
            return $item;
         }
      }

       return null;
   }

   public function findAll(): array {
       return $this->items;
   }

   public function delete(AuthConfigId $id): void {
   }

   public function exists(AuthConfigId $id): bool {
       return $this->findById($id) !== null;
   }
}

/**
 * @internal test double
 */
final class FakeHttpEngine implements HttpEngineInterface
{
   public int $calls = 0;
    /** @var list<string> */
   public array $requestedPaths = [];
    /** @var list<HttpResponse> */
   private array $responses;

    /** @param HttpResponse|list<HttpResponse> $responses */
   public function __construct(HttpResponse|array $responses) {
       $this->responses = $responses instanceof HttpResponse ? [$responses] : array_values($responses);
   }

   public function execute(AuthConfig $authConfig, HttpRequest $request): HttpResponse {
       $this->calls++;
       $this->requestedPaths[] = $request->path;

       $index = min($this->calls - 1, count($this->responses) - 1);
       return $this->responses[$index];
   }
}

/**
 * @internal test double
 */
final class FakeSyncItemWriter implements SyncItemWriterInterface
{
    /** @var list<array<string, mixed>> */
   public array $written = [];

   public function __construct(
        private readonly bool $alwaysSucceeds = true
    ) {
   }

   public function write(ReconciliationContext $context, array $mappedFields): bool {
      if (!$this->alwaysSucceeds) {
          return false;
      }

       $this->written[] = $mappedFields;

       return true;
   }
}


/**
 * @internal test double — counts writes without retaining all mapped records.
 */
final class CountingSyncItemWriter implements SyncItemWriterInterface
{
   public int $count = 0;

   public function write(ReconciliationContext $context, array $mappedFields): bool {
      $this->count++;
      return true;
   }
}

/**
 * @internal test double
 */
final class FakeSyncExecutionRepository implements SyncExecutionRepositoryInterface
{
   private int $nextId = 1;

   public function save(SyncExecution $execution): SyncExecution {
      if ($execution->id() === null) {
          $execution->assignId(new SyncExecutionId($this->nextId++));
      }

       return $execution;
   }

   public function findById(SyncExecutionId $id): ?SyncExecution {
       return null;
   }

   public function findBySyncJobId(SyncJobId $syncJobId): array {
       return [];
   }

   public function delete(SyncExecutionId $id): void {
   }
}

/**
 * @internal test double
 */
final class ExecuteSyncJobRecordingTransactionManager implements TransactionManagerInterface
{
    /** @var list<string> */
   public array $calls = [];

   public function begin(): void {
       $this->calls[] = 'begin';
   }

   public function commit(): void {
       $this->calls[] = 'commit';
   }

   public function rollback(): void {
       $this->calls[] = 'rollback';
   }
}

/**
 * @internal test double
 */
final class ExecuteSyncJobRecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<DomainEvent> */
   public array $dispatched = [];

   public function dispatch(DomainEvent $event): void {
       $this->dispatched[] = $event;
   }
}
