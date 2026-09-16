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

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetItemtype::class)]
final class TargetItemtypeTest extends TestCase
{
   public function testEveryCaseHasANonEmptyLabel(): void {
      foreach (TargetItemtype::cases() as $case) {
          self::assertNotSame('', $case->label());
      }
   }

   public function testEveryCaseMapsToALeadingBackslashedGlpiClassName(): void {
      foreach (TargetItemtype::cases() as $case) {
          self::assertStringStartsWith('\\', $case->glpiClassName());
      }
   }

   public function testComputerMapsToTheGlpiComputerClass(): void {
       self::assertSame('\\Computer', TargetItemtype::COMPUTER->glpiClassName());
   }

   public function testUserMapsToTheGlpiUserClass(): void {
       self::assertSame('\\User', TargetItemtype::USER->glpiClassName());
   }

   public function testProjectAndTicketMapToCoreGlpiClasses(): void {
       self::assertSame('\\Project', TargetItemtype::PROJECT->glpiClassName());
       self::assertSame('\\Ticket', TargetItemtype::TICKET->glpiClassName());
   }

   public function testEveryGlpiClassNameIsUnique(): void {
       $classNames = array_map(
           static fn (TargetItemtype $case): string => $case->glpiClassName(),
           TargetItemtype::cases()
       );

       self::assertSame($classNames, array_unique($classNames));
   }
}
