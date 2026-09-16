<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\Sync\Runtime;

use GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime\SyncRuntimeGuard;
use PHPUnit\Framework\TestCase;

final class SyncRuntimeGuardTest extends TestCase
{
   public function testNestedGuardRemainsActiveUntilOuterWriteCompletes(): void {
       self::assertFalse(SyncRuntimeGuard::active());

       SyncRuntimeGuard::run(function (): void {
           self::assertTrue(SyncRuntimeGuard::active());
           SyncRuntimeGuard::run(static function (): void {
               self::assertTrue(SyncRuntimeGuard::active());
           });
           self::assertTrue(SyncRuntimeGuard::active());
       });

       self::assertFalse(SyncRuntimeGuard::active());
   }
}
