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

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ObjectLinkId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectLink::class)]
final class ObjectLinkTest extends TestCase
{
   public function testCreateStartsWithoutAnIdentifierAndAFreshTimestamp(): void {
       $link = ObjectLink::create(
           new SyncJobId(7),
           new AuthConfigReference(1),
           new ExternalId('zbx-host-42'),
           TargetItemtype::COMPUTER,
           17
       );

       self::assertNull($link->id());
       self::assertSame(7, $link->syncJobId()->value());
       self::assertSame(1, $link->authConfigReference()->value());
       self::assertSame('zbx-host-42', $link->externalId()->value());
       self::assertSame(TargetItemtype::COMPUTER, $link->itemtype());
       self::assertSame(17, $link->itemId());
   }

   public function testAssignIdCanOnlyHappenOnce(): void {
       $link = ObjectLink::create(
           new SyncJobId(7),
           new AuthConfigReference(1),
           new ExternalId('zbx-host-42'),
           TargetItemtype::COMPUTER,
           17
       );

       $link->assignId(new ObjectLinkId(1));

       $this->expectException(\LogicException::class);

       $link->assignId(new ObjectLinkId(2));
   }

   public function testTouchUpdatesLastSeenAt(): void {
       $link = ObjectLink::reconstitute(
           new ObjectLinkId(1),
           new SyncJobId(7),
           new AuthConfigReference(1),
           new ExternalId('zbx-host-42'),
           TargetItemtype::COMPUTER,
           17,
           new \DateTimeImmutable('2026-01-01 00:00:00')
       );

       $link->touch();

       self::assertGreaterThan(
           new \DateTimeImmutable('2026-01-01 00:00:00'),
           $link->lastSeenAt()
       );
   }

   public function testReconstituteSetsEveryField(): void {
       $link = ObjectLink::reconstitute(
           new ObjectLinkId(5),
           new SyncJobId(9),
           new AuthConfigReference(2),
           new ExternalId('sys-id-999'),
           TargetItemtype::USER,
           42,
           new \DateTimeImmutable('2026-03-01 10:00:00')
       );

       self::assertSame(5, $link->id()?->value());
       self::assertSame(9, $link->syncJobId()->value());
       self::assertSame(2, $link->authConfigReference()->value());
       self::assertSame('sys-id-999', $link->externalId()->value());
       self::assertSame(TargetItemtype::USER, $link->itemtype());
       self::assertSame(42, $link->itemId());
       self::assertEquals(new \DateTimeImmutable('2026-03-01 10:00:00'), $link->lastSeenAt());
   }
}
