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

use GlpiPlugin\Uxconnector\Application\Sync\DTO\SyncJobDTO;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ListSyncJobsService;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
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

#[CoversClass(ListSyncJobsService::class)]
final class ListSyncJobsServiceTest extends TestCase
{
   private function makeJob(int $id, string $name): SyncJob {
       $job = SyncJob::create(
           new SyncJobName($name),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [new FieldMapping('serialNumber', 'serial', required: true)]
       );
       $job->assignId(new SyncJobId($id));

       return $job;
   }

   public function testExecuteMapsEveryPersistedSyncJobToADto(): void {
       $jobs = [$this->makeJob(1, 'Job A'), $this->makeJob(2, 'Job B')];

       $repository = new class($jobs) implements SyncJobRepositoryInterface {
         public function __construct(private readonly array $items) {
         }

         public function save(SyncJob $syncJob): SyncJob {
             return $syncJob;
         }

         public function findById(SyncJobId $id): ?SyncJob {
             return null;
         }

         public function findAll(): array {
             return $this->items;
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

         $service = new ListSyncJobsService($repository);

         $result = $service->execute();

         self::assertCount(2, $result);
         self::assertContainsOnlyInstancesOf(SyncJobDTO::class, $result);
         self::assertSame('Job A', $result[0]->name);
         self::assertSame('Job B', $result[1]->name);
   }

   public function testExecuteReturnsEmptyArrayWhenNoSyncJobExists(): void {
       $repository = new class implements SyncJobRepositoryInterface {
         public function save(SyncJob $syncJob): SyncJob {
              return $syncJob;
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

         $service = new ListSyncJobsService($repository);

         self::assertSame([], $service->execute());
   }
}
