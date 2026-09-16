<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * Nécessite une instance GLPI de test réelle (GLPI_ROOT défini).
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Integration\Persistence;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobPersistenceMapper;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobRepository
 */
#[Group('integration')]
#[CoversClass(SyncJobRepository::class)]
final class SyncJobRepositoryTest extends TestCase
{
   private SyncJobRepository $repository;

    /** @var list<int> */
   private array $createdIds = [];

   protected function setUp(): void {
      if (!defined('GLPI_ROOT')) {
          self::markTestSkipped('Requires a real GLPI instance (GLPI_ROOT).');
      }

       $this->repository = new SyncJobRepository(new SyncJobPersistenceMapper());
   }

   private function makeSyncJob(string $name): SyncJob {
       return SyncJob::create(
           new SyncJobName($name),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [
               new FieldMapping('serialNumber', 'serial', required: true),
               new FieldMapping('mail', 'email1', transform: 'trim'),
           ]
       );
   }

   public function testSaveThenFindByIdRoundTripsFieldMappingsCorrectly(): void {
       $syncJob = $this->makeSyncJob('Integration Test SyncJob');

       $saved = $this->repository->save($syncJob);
       $this->createdIds[] = $saved->id()->value();

       $found = $this->repository->findById($saved->id());

       self::assertNotNull($found);
       self::assertSame('Integration Test SyncJob', $found->name()->value());
       self::assertSame(TargetItemtype::COMPUTER, $found->targetItemtype());
       self::assertSame('serial', $found->matchField()->value());
       self::assertSame('id', $found->externalIdField()->value());
       self::assertCount(2, $found->fieldMappings());
       self::assertSame('serialNumber', $found->fieldMappings()[0]->sourceField());
       self::assertSame('trim', $found->fieldMappings()[1]->transform());
   }

   public function testFindRunnableOnlyReturnsActiveJobs(): void {
       $draft = $this->makeSyncJob('Integration Test SyncJob - draft');
       $draftSaved = $this->repository->save($draft);
       $this->createdIds[] = $draftSaved->id()->value();

       $active = $this->makeSyncJob('Integration Test SyncJob - active');
       $active->activate();
       $activeSaved = $this->repository->save($active);
       $this->createdIds[] = $activeSaved->id()->value();

       $runnableIds = array_map(
           static fn (SyncJob $job): int => $job->id()->value(),
           $this->repository->findRunnable()
       );

       self::assertContains($activeSaved->id()->value(), $runnableIds);
       self::assertNotContains($draftSaved->id()->value(), $runnableIds);
   }

   public function testDeleteRemovesTheSyncJob(): void {
       $syncJob = $this->makeSyncJob('Integration Test SyncJob - to delete');
       $saved = $this->repository->save($syncJob);

       $this->repository->delete($saved->id());

       self::assertNull($this->repository->findById($saved->id()));
       self::assertFalse($this->repository->exists($saved->id()));
   }

   protected function tearDown(): void {
      foreach ($this->createdIds as $id) {
          $this->repository->delete(new SyncJobId($id));
      }

       $this->createdIds = [];
   }
}
