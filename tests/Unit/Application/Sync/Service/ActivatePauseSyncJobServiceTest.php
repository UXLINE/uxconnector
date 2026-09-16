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

use GlpiPlugin\Uxconnector\Application\Sync\Command\ActivateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Command\PauseSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ActivateSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\PauseSyncJobService;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
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

#[CoversClass(ActivateSyncJobService::class)]
#[CoversClass(PauseSyncJobService::class)]
final class ActivatePauseSyncJobServiceTest extends TestCase
{
   private function makeSyncJob(bool $withFieldMappings = true): SyncJob {
       $syncJob = SyncJob::create(
           new SyncJobName('MDM inventory sync'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           $withFieldMappings ? [new FieldMapping('serialNumber', 'serial', required: true)] : []
       );
       $syncJob->assignId(new SyncJobId(1));

       return $syncJob;
   }

   public function testActivateSucceedsWhenFieldMappingsExist(): void {
       $repository = new InMemorySyncJobRepoForLifecycle([$this->makeSyncJob(true)]);
       $service = new ActivateSyncJobService($repository);

       $syncJob = $service->execute(new ActivateSyncJobCommand(new SyncJobId(1)));

       self::assertSame(SyncJobStatus::ACTIVE, $syncJob->status());
   }

   public function testActivateFailsWithoutFieldMappings(): void {
       $repository = new InMemorySyncJobRepoForLifecycle([$this->makeSyncJob(false)]);
       $service = new ActivateSyncJobService($repository);

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::class);

       $service->execute(new ActivateSyncJobCommand(new SyncJobId(1)));
   }

   public function testActivateRejectsUnknownSyncJob(): void {
       $service = new ActivateSyncJobService(new InMemorySyncJobRepoForLifecycle([]));

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::class);

       $service->execute(new ActivateSyncJobCommand(new SyncJobId(999)));
   }

   public function testPauseWorksFromActive(): void {
       $syncJob = $this->makeSyncJob(true);
       $syncJob->activate();

       $repository = new InMemorySyncJobRepoForLifecycle([$syncJob]);
       $service = new PauseSyncJobService($repository);

       $paused = $service->execute(new PauseSyncJobCommand(new SyncJobId(1)));

       self::assertSame(SyncJobStatus::PAUSED, $paused->status());
   }

   public function testPausedJobCanBeReactivated(): void {
       $syncJob = $this->makeSyncJob(true);
       $syncJob->activate();
       $syncJob->pause();

       $repository = new InMemorySyncJobRepoForLifecycle([$syncJob]);
       $service = new ActivateSyncJobService($repository);

       $reactivated = $service->execute(new ActivateSyncJobCommand(new SyncJobId(1)));

       self::assertSame(SyncJobStatus::ACTIVE, $reactivated->status());
   }

   public function testPauseRejectsUnknownSyncJob(): void {
       $service = new PauseSyncJobService(new InMemorySyncJobRepoForLifecycle([]));

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::class);

       $service->execute(new PauseSyncJobCommand(new SyncJobId(999)));
   }
}

/**
 * @internal test double
 */
final class InMemorySyncJobRepoForLifecycle implements SyncJobRepositoryInterface
{
    /** @var array<int, SyncJob> */
   private array $storage = [];

   public function __construct(array $items) {
      foreach ($items as $item) {
          $this->storage[$item->id()->value()] = $item;
      }
   }

   public function save(SyncJob $syncJob): SyncJob {
       $this->storage[$syncJob->id()->value()] = $syncJob;

       return $syncJob;
   }

   public function findById(SyncJobId $id): ?SyncJob {
       return $this->storage[$id->value()] ?? null;
   }

   public function findAll(): array {
       return array_values($this->storage);
   }

   public function findRunnable(): array {
       return array_values(array_filter(
           $this->storage,
           static fn (SyncJob $job): bool => $job->isRunnable()
       ));
   }

   public function delete(SyncJobId $id): void {
       unset($this->storage[$id->value()]);
   }

   public function exists(SyncJobId $id): bool {
       return isset($this->storage[$id->value()]);
   }
}
