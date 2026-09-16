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

use GlpiPlugin\Uxconnector\Application\Sync\Command\CreateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Service\CreateSyncJobService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Event\SyncJobCreatedEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateSyncJobService::class)]
final class CreateSyncJobServiceTest extends TestCase
{
   private function makeCommand(): CreateSyncJobCommand {
       return new CreateSyncJobCommand(
           new SyncJobName('MDM inventory sync'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [new FieldMapping('serialNumber', 'serial', required: true)]
       );
   }

   public function testHappyPathPersistsAndDispatches(): void {
       $syncJobRepository = new InMemorySyncJobRepository();
       $authConfigRepository = new StubAuthConfigRepository(existingIds: [1]);
       $transaction = new RecordingTransactionManager();
       $events = new RecordingEventDispatcher();

       $service = new CreateSyncJobService($syncJobRepository, $authConfigRepository, new TargetFieldPolicy(), $transaction, $events);

       $syncJob = $service->execute($this->makeCommand());

       self::assertInstanceOf(SyncJob::class, $syncJob);
       self::assertNotNull($syncJob->id());
       self::assertSame(['begin', 'commit'], $transaction->calls);
       self::assertCount(1, $events->dispatched);
       self::assertInstanceOf(SyncJobCreatedEvent::class, $events->dispatched[0]);
   }

   public function testCreatedJobStartsInDraftEvenWithFieldMappingsSupplied(): void {
       $service = new CreateSyncJobService(
           new InMemorySyncJobRepository(),
           new StubAuthConfigRepository(existingIds: [1]),
           new TargetFieldPolicy(),
           new RecordingTransactionManager(),
           new RecordingEventDispatcher()
       );

       $syncJob = $service->execute($this->makeCommand());

       self::assertSame('draft', $syncJob->status()->value);
       self::assertFalse($syncJob->isRunnable());
   }

   public function testUnknownAuthConfigReferenceIsRejectedBeforeAnyPersistence(): void {
       $syncJobRepository = new InMemorySyncJobRepository();
       $authConfigRepository = new StubAuthConfigRepository(existingIds: []); // AuthConfig #1 does not exist
       $transaction = new RecordingTransactionManager();
       $events = new RecordingEventDispatcher();

       $service = new CreateSyncJobService($syncJobRepository, $authConfigRepository, new TargetFieldPolicy(), $transaction, $events);

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigNotFoundException::class);

      try {
          $service->execute($this->makeCommand());
      } finally {
          self::assertSame([], $syncJobRepository->saved, 'no SyncJob should have been persisted');
          self::assertSame([], $transaction->calls, 'no transaction should have been opened');
          self::assertSame([], $events->dispatched);
      }
   }

    /**
     * CORRECTIF P0 (revue de code externe) : avant ce correctif,
     * OUTBOUND et BIDIRECTIONAL étaient acceptés à la création alors
     * qu'ExecuteSyncJobService les exécutait silencieusement comme
     * INBOUND. Vérifie également que le rejet intervient avant la
     * vérification d'existence de l'AuthConfig (défense en
     * profondeur : le premier obstacle rencontré doit être le bon,
     * quel que soit l'ordre des autres erreurs possibles).
     */
   public function testUnimplementedDirectionIsRejectedBeforeAnyPersistence(): void {
       $syncJobRepository = new InMemorySyncJobRepository();
       $authConfigRepository = new StubAuthConfigRepository(existingIds: [1]);
       $transaction = new RecordingTransactionManager();
       $events = new RecordingEventDispatcher();

       $service = new CreateSyncJobService($syncJobRepository, $authConfigRepository, new TargetFieldPolicy(), $transaction, $events);

       $command = new CreateSyncJobCommand(
           new SyncJobName('Broken bidirectional job'),
           new AuthConfigReference(1),
           SyncDirection::BIDIRECTIONAL,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [new FieldMapping('serialNumber', 'serial', required: true)]
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncDirectionNotSupportedException::class);

      try {
          $service->execute($command);
      } finally {
          self::assertSame([], $syncJobRepository->saved, 'no SyncJob should have been persisted');
          self::assertSame([], $transaction->calls, 'no transaction should have been opened');
          self::assertSame([], $events->dispatched);
      }
   }

   public function testRepositoryFailureRollsBackAndNeverDispatches(): void {
       $syncJobRepository = new class implements SyncJobRepositoryInterface {
         public function save(SyncJob $syncJob): SyncJob {
            throw new \RuntimeException('database is on fire');
         }

         public function findById(SyncJobId $id): ?SyncJob {
             return null;
         }

         public function findAll(): array {
             return [];
         }

         public function findRunnable(): array {
             return [];
         }

         public function delete(SyncJobId $id): void {
         }

         public function exists(SyncJobId $id): bool {
             return false;
         }
       };

         $transaction = new RecordingTransactionManager();
         $events = new RecordingEventDispatcher();

         $service = new CreateSyncJobService(
           $syncJobRepository,
           new StubAuthConfigRepository(existingIds: [1]),
           new TargetFieldPolicy(),
           $transaction,
           $events
       );

      try {
          $service->execute($this->makeCommand());
          self::fail('Expected exception was not thrown.');
      } catch (\RuntimeException) {
          self::addToAssertionCount(1);
      }

       self::assertSame(['begin', 'rollback'], $transaction->calls);
       self::assertSame([], $events->dispatched);
   }
}

/**
 * @internal test double
 */
final class InMemorySyncJobRepository implements SyncJobRepositoryInterface
{
    /** @var array<int, SyncJob> */
   public array $saved = [];
   private int $nextId = 1;

   public function save(SyncJob $syncJob): SyncJob {
      if ($syncJob->id() === null) {
          $syncJob->assignId(new SyncJobId($this->nextId++));
      }

       $this->saved[$syncJob->id()->value()] = $syncJob;

       return $syncJob;
   }

   public function findById(SyncJobId $id): ?SyncJob {
       return $this->saved[$id->value()] ?? null;
   }

   public function findAll(): array {
       return array_values($this->saved);
   }

   public function findRunnable(): array {
       return array_values(array_filter($this->saved, fn (SyncJob $j) => $j->isRunnable()));
   }

   public function delete(SyncJobId $id): void {
       unset($this->saved[$id->value()]);
   }

   public function exists(SyncJobId $id): bool {
       return isset($this->saved[$id->value()]);
   }
}

/**
 * @internal test double
 */
final class StubAuthConfigRepository implements AuthConfigRepositoryInterface
{
    /**
     * @param list<int> $existingIds
     */
   public function __construct(private readonly array $existingIds) {
   }

   public function save(AuthConfig $authConfig): AuthConfig {
       return $authConfig;
   }

   public function findById(AuthConfigId $id): ?AuthConfig {
       return null;
   }

   public function findAll(): array {
       return [];
   }

   public function delete(AuthConfigId $id): void {
   }

   public function exists(AuthConfigId $id): bool {
       return in_array($id->value(), $this->existingIds, true);
   }
}

/**
 * @internal test double
 */
final class RecordingTransactionManager implements TransactionManagerInterface
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
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<DomainEvent> */
   public array $dispatched = [];

   public function dispatch(DomainEvent $event): void {
       $this->dispatched[] = $event;
   }
}
