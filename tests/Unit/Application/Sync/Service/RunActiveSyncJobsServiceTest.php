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
use GlpiPlugin\Uxconnector\Application\Sync\Service\SyncJobExecutorInterface;
use GlpiPlugin\Uxconnector\Application\Sync\Service\RunActiveSyncJobsService;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunActiveSyncJobsService::class)]
final class RunActiveSyncJobsServiceTest extends TestCase
{
   private function makeRunnableJob(int $id): SyncJob {
       $job = SyncJob::create(
           new SyncJobName('Job ' . $id),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('b'),
           new ExternalIdField('id'),
           [new FieldMapping('a', 'b', required: true)]
       );
       $job->assignId(new SyncJobId($id));
       $job->activate();
       $job->releaseEvents();

       return $job;
   }

   public function testNoRunnableJobsYieldsAnEmptyResult(): void {
       $repository = $this->createRepositoryReturning([]);
       $executeService = $this->createMock(SyncJobExecutorInterface::class);
       $executeService->expects(self::never())->method('execute');

       $service = new RunActiveSyncJobsService($repository, $executeService);

       $result = $service->execute();

       self::assertSame(0, $result->totalJobs);
       self::assertFalse($result->hasRun());
   }

   public function testEveryRunnableJobIsExecutedAndCountedAsSucceeded(): void {
       $jobs = [$this->makeRunnableJob(1), $this->makeRunnableJob(2)];
       $repository = $this->createRepositoryReturning($jobs);

       $executeService = $this->createMock(SyncJobExecutorInterface::class);
       $executeService->expects(self::exactly(2))
           ->method('execute')
           ->willReturnCallback(function (ExecuteSyncJobCommand $command) {
               $execution = SyncExecution::start($command->syncJobId);
               $execution->assignId(new SyncExecutionId($command->syncJobId->value()));
               $execution->complete(itemsProcessed: 1, itemsFailed: 0);

               return $execution;
           });

       $service = new RunActiveSyncJobsService($repository, $executeService);

       $result = $service->execute();

       self::assertSame(2, $result->totalJobs);
       self::assertSame(2, $result->succeeded);
       self::assertSame(0, $result->failed);
       self::assertTrue($result->isFullySuccessful());
   }

   public function testAFailedSyncExecutionIsCountedAsFailedWithoutStoppingTheBatch(): void {
       $jobs = [$this->makeRunnableJob(1), $this->makeRunnableJob(2)];
       $repository = $this->createRepositoryReturning($jobs);

       $executeService = $this->createMock(SyncJobExecutorInterface::class);
       $executeService->expects(self::exactly(2))
           ->method('execute')
           ->willReturnCallback(function (ExecuteSyncJobCommand $command) {
               $execution = SyncExecution::start($command->syncJobId);
               $execution->assignId(new SyncExecutionId($command->syncJobId->value()));

            if ($command->syncJobId->value() === 1) {
               $execution->fail('boom');
            } else {
               $execution->complete(1, 0);
            }

               return $execution;
           });

       $service = new RunActiveSyncJobsService($repository, $executeService);

       $result = $service->execute();

       self::assertSame(2, $result->totalJobs);
       self::assertSame(1, $result->succeeded);
       self::assertSame(1, $result->failed);
       self::assertFalse($result->isFullySuccessful());
   }

   public function testAnUnexpectedExceptionOnOneJobDoesNotPreventTheOthersFromRunning(): void {
       $jobs = [$this->makeRunnableJob(1), $this->makeRunnableJob(2)];
       $repository = $this->createRepositoryReturning($jobs);

       $executeService = $this->createMock(SyncJobExecutorInterface::class);
       $executeService->expects(self::exactly(2))
           ->method('execute')
           ->willReturnCallback(function (ExecuteSyncJobCommand $command) {
            if ($command->syncJobId->value() === 1) {
               throw new \RuntimeException('SyncExecution repository unavailable');
            }

               $execution = SyncExecution::start($command->syncJobId);
               $execution->assignId(new SyncExecutionId($command->syncJobId->value()));
               $execution->complete(1, 0);

               return $execution;
           });

       $service = new RunActiveSyncJobsService($repository, $executeService);

       $result = $service->execute();

       self::assertSame(2, $result->totalJobs);
       self::assertSame(1, $result->succeeded);
       self::assertSame(1, $result->failed);
       self::assertSame(['SyncExecution repository unavailable'], array_values($result->errors));
   }

    /**
     * @param list<SyncJob> $jobs
     */
   private function createRepositoryReturning(array $jobs): SyncJobRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject {
       $repository = $this->createMock(SyncJobRepositoryInterface::class);
       $repository->method('findRunnable')->willReturn($jobs);

       return $repository;
   }
}
