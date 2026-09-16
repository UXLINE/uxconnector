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

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldMapping::class)]
final class FieldMappingTest extends TestCase
{
   public function testValidMappingIsAccepted(): void {
       $mapping = new FieldMapping('mail', 'email1', required: true, transform: 'trim');

       self::assertSame('mail', $mapping->sourceField());
       self::assertSame('email1', $mapping->targetField());
       self::assertTrue($mapping->isRequired());
       self::assertSame('trim', $mapping->transform());
   }

   public function testDefaultsAreNotRequiredAndNoTransform(): void {
       $mapping = new FieldMapping('mail', 'email1');

       self::assertFalse($mapping->isRequired());
       self::assertNull($mapping->transform());
   }

   public function testEmptySourceFieldIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new FieldMapping('   ', 'email1');
   }

   public function testEmptyTargetFieldIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new FieldMapping('mail', '   ');
   }

   public function testFieldExceedingMaxLengthIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new FieldMapping(str_repeat('a', 129), 'email1');
   }

   public function testFieldsAreTrimmed(): void {
       $mapping = new FieldMapping('  mail  ', '  email1  ');

       self::assertSame('mail', $mapping->sourceField());
       self::assertSame('email1', $mapping->targetField());
   }

   public function testEqualsComparesEveryAttribute(): void {
       $a = new FieldMapping('mail', 'email1', required: true);
       $b = new FieldMapping('mail', 'email1', required: true);
       $differentRequired = new FieldMapping('mail', 'email1', required: false);

       self::assertTrue($a->equals($b));
       self::assertFalse($a->equals($differentRequired));
   }
}
