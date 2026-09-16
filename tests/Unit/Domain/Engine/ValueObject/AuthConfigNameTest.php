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

use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthConfigName::class)]
final class AuthConfigNameTest extends TestCase
{
   public function testValidNameIsAccepted(): void {
       $name = new AuthConfigName('Microsoft Graph - Production');

       self::assertSame('Microsoft Graph - Production', $name->value());
   }

   public function testSurroundingWhitespaceIsTrimmed(): void {
       $name = new AuthConfigName('  Zabbix  ');

       self::assertSame('Zabbix', $name->value());
   }

   public function testNameShorterThanMinimumIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new AuthConfigName('A');
   }

   public function testNameLongerThanMaximumIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new AuthConfigName(str_repeat('a', 129));
   }

   public function testEqualsComparesByValue(): void {
       self::assertTrue(
           (new AuthConfigName('Jira'))->equals(new AuthConfigName('Jira'))
       );
       self::assertFalse(
           (new AuthConfigName('Jira'))->equals(new AuthConfigName('Zabbix'))
       );
   }
}
