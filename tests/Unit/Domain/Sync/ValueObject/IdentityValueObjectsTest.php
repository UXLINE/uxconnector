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

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncJobId::class)]
#[CoversClass(SyncExecutionId::class)]
#[CoversClass(AuthConfigReference::class)]
#[CoversClass(SyncJobName::class)]
#[CoversClass(ResourcePath::class)]
final class IdentityValueObjectsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string}>
     */
   public static function identityClassProvider(): iterable {
       yield 'SyncJobId' => [SyncJobId::class];
       yield 'SyncExecutionId' => [SyncExecutionId::class];
       yield 'AuthConfigReference' => [AuthConfigReference::class];
   }

    #[DataProvider('identityClassProvider')]
   public function testZeroIsRejected(string $class): void {
       $this->expectException(\InvalidArgumentException::class);

       new $class(0);
   }

    #[DataProvider('identityClassProvider')]
   public function testNegativeValueIsRejected(string $class): void {
       $this->expectException(\InvalidArgumentException::class);

       new $class(-1);
   }

    #[DataProvider('identityClassProvider')]
   public function testPositiveValueIsAcceptedAndReturned(string $class): void {
       $instance = new $class(42);

       self::assertSame(42, $instance->value());
       self::assertSame('42', (string) $instance);
   }

    #[DataProvider('identityClassProvider')]
   public function testEqualsComparesByValue(string $class): void {
       self::assertTrue((new $class(1))->equals(new $class(1)));
       self::assertFalse((new $class(1))->equals(new $class(2)));
   }

   public function testSyncJobNameEnforcesBounds(): void {
       $this->expectException(\InvalidArgumentException::class);

       new SyncJobName('A');
   }

   public function testSyncJobNameTrimsAndAccepts(): void {
       $name = new SyncJobName('  MDM inventory sync  ');

       self::assertSame('MDM inventory sync', $name->value());
   }

   public function testResourcePathMustStartWithSlash(): void {
       $this->expectException(\InvalidArgumentException::class);

       new ResourcePath('computers');
   }

   public function testResourcePathRejectsEmptyValue(): void {
       $this->expectException(\InvalidArgumentException::class);

       new ResourcePath('   ');
   }

   public function testResourcePathAcceptsAValidValue(): void {
       $path = new ResourcePath('/computers');

       self::assertSame('/computers', $path->value());
       self::assertSame('/computers', (string) $path);
   }

   public function testResourcePathEqualsComparesByValue(): void {
       self::assertTrue((new ResourcePath('/computers'))->equals(new ResourcePath('/computers')));
       self::assertFalse((new ResourcePath('/computers'))->equals(new ResourcePath('/users')));
   }
}
