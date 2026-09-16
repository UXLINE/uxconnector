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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Sync\ValueObject;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatchField::class)]
final class MatchFieldTest extends TestCase
{
   public function testValidValueIsAccepted(): void {
       $field = new MatchField('serial');

       self::assertSame('serial', $field->value());
   }

   public function testValueIsTrimmed(): void {
       $field = new MatchField('  serial  ');

       self::assertSame('serial', $field->value());
   }

   public function testEmptyValueIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new MatchField('   ');
   }

   public function testValueExceedingMaxLengthIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new MatchField(str_repeat('a', 129));
   }

   public function testEqualsComparesByValue(): void {
       self::assertTrue((new MatchField('serial'))->equals(new MatchField('serial')));
       self::assertFalse((new MatchField('serial'))->equals(new MatchField('email1')));
   }
}
