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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Sync\Entity;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Event\SyncExecutionFinishedEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncExecution::class)]
final class SyncExecutionTest extends TestCase
{
   public function testStartBeginsInRunningStatus(): void {
       $execution = SyncExecution::start(new SyncJobId(1));

       self::assertSame(SyncExecutionStatus::RUNNING, $execution->status());
       self::assertNull($execution->finishedAt());
       self::assertNull($execution->id());
   }

   public function testCompleteWithoutFailuresIsSuccess(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));

       $execution->complete(itemsProcessed: 10, itemsFailed: 0);

       self::assertSame(SyncExecutionStatus::SUCCESS, $execution->status());
       self::assertSame(10, $execution->itemsProcessed());
       self::assertSame(0, $execution->itemsFailed());
       self::assertNotNull($execution->finishedAt());
   }

   public function testCompleteWithSomeFailuresIsPartial(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));

       $execution->complete(itemsProcessed: 10, itemsFailed: 3);

       self::assertSame(SyncExecutionStatus::PARTIAL, $execution->status());
   }

   public function testCompleteWithZeroSuccessesAndSomeFailuresIsFailed(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));

       $execution->complete(itemsProcessed: 0, itemsFailed: 3);

       self::assertSame(SyncExecutionStatus::FAILED, $execution->status());
   }

   public function testCompleteWithZeroItemsAtAllIsSuccess(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));

       $execution->complete(itemsProcessed: 0, itemsFailed: 0);

       self::assertSame(SyncExecutionStatus::SUCCESS, $execution->status());
   }

   public function testFailRecordsErrorMessage(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));

       $execution->fail('Connection timed out after 30s.');

       self::assertSame(SyncExecutionStatus::FAILED, $execution->status());
       self::assertSame('Connection timed out after 30s.', $execution->errorMessage());
   }

   public function testCompleteAfterAlreadyFinishedIsRejected(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));
       $execution->complete(10, 0);

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncExecutionStateException::class);

       $execution->complete(5, 0);
   }

   public function testFailAfterAlreadyFinishedIsRejected(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));
       $execution->complete(10, 0);

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncExecutionStateException::class);

       $execution->fail('too late');
   }

   public function testNegativeItemCountsAreRejected(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncExecutionStateException::class);

       $execution->complete(-1, 0);
   }

   public function testFinishedEventIsRecordedOnlyAfterIdIsAssigned(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->complete(10, 0); // no assignId() yet

       self::assertSame([], $execution->releaseEvents());
   }

   public function testFinishedEventIsRecordedWhenIdIsAssignedBeforeCompletion(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(7));

       $execution->complete(10, 2);

       $events = $execution->releaseEvents();

       self::assertCount(1, $events);
       self::assertInstanceOf(SyncExecutionFinishedEvent::class, $events[0]);
       self::assertSame(7, $events[0]->executionId->value());
       self::assertSame(SyncExecutionStatus::PARTIAL, $events[0]->status);
   }

   public function testAssignIdCannotBeCalledTwice(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncExecutionStateException::class);

       $execution->assignId(new SyncExecutionId(2));
   }

   public function testReconstituteDoesNotRecordAnyEvent(): void {
       $execution = SyncExecution::reconstitute(
           new SyncExecutionId(1),
           new SyncJobId(1),
           SyncExecutionStatus::SUCCESS,
           new \DateTimeImmutable('2026-01-01 10:00:00'),
           new \DateTimeImmutable('2026-01-01 10:05:00'),
           42,
           0,
           null
       );

       self::assertSame([], $execution->releaseEvents());
       self::assertSame(42, $execution->itemsProcessed());
   }
}
