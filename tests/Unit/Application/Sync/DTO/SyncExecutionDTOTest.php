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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\DTO;

use GlpiPlugin\Uxconnector\Application\Sync\DTO\SyncExecutionDTO;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncExecutionDTO::class)]
final class SyncExecutionDTOTest extends TestCase
{
   public function testFromEntityMapsACompletedExecution(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(9));
       $execution->complete(42, 3);

       $dto = SyncExecutionDTO::fromEntity($execution);

       self::assertSame(9, $dto->id);
       self::assertSame(1, $dto->syncJobId);
       self::assertSame('partial', $dto->status);
       self::assertSame(42, $dto->itemsProcessed);
       self::assertSame(3, $dto->itemsFailed);
       self::assertNotNull($dto->finishedAt);
       self::assertNotNull($dto->durationSeconds);
       self::assertGreaterThanOrEqual(0, $dto->durationSeconds);
       self::assertNull($dto->errorMessage);
   }

   public function testFromEntityMapsAFailedExecutionWithItsErrorMessage(): void {
       $execution = SyncExecution::start(new SyncJobId(1));
       $execution->assignId(new SyncExecutionId(1));
       $execution->fail('External system returned HTTP 503.');

       $dto = SyncExecutionDTO::fromEntity($execution);

       self::assertSame('failed', $dto->status);
       self::assertSame('External system returned HTTP 503.', $dto->errorMessage);
   }

   public function testFromEntityRejectsAggregateWithoutId(): void {
       $execution = SyncExecution::start(new SyncJobId(1));

       $this->expectException(\LogicException::class);

       SyncExecutionDTO::fromEntity($execution);
   }
}
