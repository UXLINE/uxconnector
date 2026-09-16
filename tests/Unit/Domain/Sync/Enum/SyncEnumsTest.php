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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Sync\Enum;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncDirection::class)]
#[CoversClass(SyncJobStatus::class)]
#[CoversClass(SyncExecutionStatus::class)]
final class SyncEnumsTest extends TestCase
{
   public function testInboundOnlyReadsFromExternalSystem(): void {
       self::assertTrue(SyncDirection::INBOUND->readsFromExternalSystem());
       self::assertFalse(SyncDirection::INBOUND->writesToExternalSystem());
   }

   public function testOutboundOnlyWritesToExternalSystem(): void {
       self::assertFalse(SyncDirection::OUTBOUND->readsFromExternalSystem());
       self::assertTrue(SyncDirection::OUTBOUND->writesToExternalSystem());
   }

   public function testBidirectionalDoesBoth(): void {
       self::assertTrue(SyncDirection::BIDIRECTIONAL->readsFromExternalSystem());
       self::assertTrue(SyncDirection::BIDIRECTIONAL->writesToExternalSystem());
   }

   public function testOnlyActiveSyncJobStatusIsRunnable(): void {
       self::assertTrue(SyncJobStatus::ACTIVE->isRunnable());
       self::assertFalse(SyncJobStatus::DRAFT->isRunnable());
       self::assertFalse(SyncJobStatus::PAUSED->isRunnable());
       self::assertFalse(SyncJobStatus::ERROR->isRunnable());
   }

   public function testTerminalSyncExecutionStatuses(): void {
       self::assertTrue(SyncExecutionStatus::SUCCESS->isFinished());
       self::assertTrue(SyncExecutionStatus::PARTIAL->isFinished());
       self::assertTrue(SyncExecutionStatus::FAILED->isFinished());
       self::assertFalse(SyncExecutionStatus::PENDING->isFinished());
       self::assertFalse(SyncExecutionStatus::RUNNING->isFinished());
   }

   public function testEveryCaseOfEveryEnumHasANonEmptyLabel(): void {
      foreach (SyncDirection::cases() as $case) {
          self::assertNotSame('', $case->label());
      }
      foreach (SyncJobStatus::cases() as $case) {
          self::assertNotSame('', $case->label());
      }
      foreach (SyncExecutionStatus::cases() as $case) {
          self::assertNotSame('', $case->label());
      }
   }

   public function testOnlyInboundIsImplemented(): void {
       self::assertTrue(SyncDirection::INBOUND->isImplemented());
       self::assertTrue(SyncDirection::OUTBOUND->isImplemented());
       self::assertFalse(SyncDirection::BIDIRECTIONAL->isImplemented());
   }
}
