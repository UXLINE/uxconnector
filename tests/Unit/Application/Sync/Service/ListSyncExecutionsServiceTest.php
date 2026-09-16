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

use GlpiPlugin\Uxconnector\Application\Sync\DTO\SyncExecutionDTO;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ListSyncExecutionsService;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListSyncExecutionsService::class)]
final class ListSyncExecutionsServiceTest extends TestCase
{
   public function testExecuteMapsEveryExecutionOfTheGivenSyncJob(): void {
       $execution1 = SyncExecution::start(new SyncJobId(1));
       $execution1->assignId(new SyncExecutionId(1));
       $execution1->complete(10, 0);

       $execution2 = SyncExecution::start(new SyncJobId(1));
       $execution2->assignId(new SyncExecutionId(2));
       $execution2->fail('boom');

       $repository = new class([$execution1, $execution2]) implements SyncExecutionRepositoryInterface {
         public function __construct(private readonly array $items) {
         }

         public function save(SyncExecution $execution): SyncExecution {
             return $execution;
         }

         public function findById(SyncExecutionId $id): ?SyncExecution {
             return null;
         }

         public function findBySyncJobId(SyncJobId $syncJobId): array {
             return $this->items;
         }

         public function delete(SyncExecutionId $id): void {
         }
       };

         $service = new ListSyncExecutionsService($repository);

         $result = $service->execute(new SyncJobId(1));

         self::assertCount(2, $result);
         self::assertContainsOnlyInstancesOf(SyncExecutionDTO::class, $result);
         self::assertSame('success', $result[0]->status);
         self::assertSame('failed', $result[1]->status);
   }

   public function testExecuteReturnsEmptyArrayWhenNoExecutionExists(): void {
       $repository = new class implements SyncExecutionRepositoryInterface {
         public function save(SyncExecution $execution): SyncExecution {
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
       };

         $service = new ListSyncExecutionsService($repository);

         self::assertSame([], $service->execute(new SyncJobId(999)));
   }
}
