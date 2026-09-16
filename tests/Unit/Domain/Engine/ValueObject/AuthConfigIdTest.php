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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Engine\ValueObject;

use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthConfigId::class)]
final class AuthConfigIdTest extends TestCase
{
   public function testValueIsReturned(): void {
       self::assertSame(42, (new AuthConfigId(42))->value());
   }

   public function testZeroIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new AuthConfigId(0);
   }

   public function testNegativeValueIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new AuthConfigId(-1);
   }

   public function testEqualsComparesByValue(): void {
       self::assertTrue((new AuthConfigId(1))->equals(new AuthConfigId(1)));
       self::assertFalse((new AuthConfigId(1))->equals(new AuthConfigId(2)));
   }

   public function testToStringReturnsValueAsString(): void {
       self::assertSame('7', (string) new AuthConfigId(7));
   }
}
