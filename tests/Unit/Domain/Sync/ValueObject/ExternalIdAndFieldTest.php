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

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExternalId::class)]
#[CoversClass(ExternalIdField::class)]
final class ExternalIdAndFieldTest extends TestCase
{
   public function testExternalIdAcceptsAndTrimsAValidValue(): void {
       self::assertSame('zbx-host-42', (new ExternalId('  zbx-host-42  '))->value());
   }

   public function testExternalIdRejectsEmptyValue(): void {
       $this->expectException(\InvalidArgumentException::class);

       new ExternalId('   ');
   }

   public function testExternalIdRejectsValueExceedingMaxLength(): void {
       $this->expectException(\InvalidArgumentException::class);

       new ExternalId(str_repeat('a', 192));
   }

   public function testExternalIdEqualsComparesByValue(): void {
       self::assertTrue((new ExternalId('abc'))->equals(new ExternalId('abc')));
       self::assertFalse((new ExternalId('abc'))->equals(new ExternalId('def')));
   }

   public function testExternalIdFieldAcceptsAndTrimsAValidValue(): void {
       self::assertSame('sys_id', (new ExternalIdField('  sys_id  '))->value());
   }

   public function testExternalIdFieldRejectsEmptyValue(): void {
       $this->expectException(\InvalidArgumentException::class);

       new ExternalIdField('');
   }

   public function testExternalIdFieldRejectsValueExceedingMaxLength(): void {
       $this->expectException(\InvalidArgumentException::class);

       new ExternalIdField(str_repeat('a', 129));
   }
}
