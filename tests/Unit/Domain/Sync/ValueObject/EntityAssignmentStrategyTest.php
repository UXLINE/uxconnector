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

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\UnknownEntityPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityAssignmentStrategy::class)]
final class EntityAssignmentStrategyTest extends TestCase
{
   public function testFixedAlwaysResolvesToTheSameEntityRegardlessOfItemContent(): void {
       $strategy = EntityAssignmentStrategy::fixed(42);

       self::assertSame(42, $strategy->resolve(['anything' => 'irrelevant']));
       self::assertSame(42, $strategy->resolve([]));
       self::assertTrue($strategy->isFixed());
       self::assertSame(42, $strategy->fixedEntityId());
   }

   public function testFixedRejectsANegativeEntityId(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidEntityAssignmentStrategyException::class);

       EntityAssignmentStrategy::fixed(-1);
   }

   public function testMappedResolvesAKnownSourceValue(): void {
       $strategy = EntityAssignmentStrategy::mapped('location', [
           'Nantes' => 42,
           'Paris' => 68,
           'Lyon' => 91,
       ]);

       self::assertSame(68, $strategy->resolve(['location' => 'Paris']));
       self::assertFalse($strategy->isFixed());
   }

   public function testMappedWithFailPolicyReturnsNullOnUnknownValue(): void {
       $strategy = EntityAssignmentStrategy::mapped('location', ['Nantes' => 42]);

       self::assertNull($strategy->resolve(['location' => 'Lille']));
   }

   public function testMappedWithFailPolicyReturnsNullWhenSourceFieldIsMissing(): void {
       $strategy = EntityAssignmentStrategy::mapped('location', ['Nantes' => 42]);

       self::assertNull($strategy->resolve([]));
   }

   public function testMappedWithUseDefaultPolicyFallsBackOnUnknownValue(): void {
       $strategy = EntityAssignmentStrategy::mapped(
           'location',
           ['Nantes' => 42],
           UnknownEntityPolicy::USE_DEFAULT,
           0
       );

       self::assertSame(0, $strategy->resolve(['location' => 'Lille']));
       self::assertSame(42, $strategy->resolve(['location' => 'Nantes']), 'a known value still takes priority over the default');
   }

   public function testMappedRejectsAnEmptySourceField(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidEntityAssignmentStrategyException::class);

       EntityAssignmentStrategy::mapped('', ['a' => 1]);
   }

   public function testMappedRejectsAnEmptyMappingTable(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidEntityAssignmentStrategyException::class);

       EntityAssignmentStrategy::mapped('location', []);
   }

   public function testUseDefaultPolicyWithoutADefaultEntityIdIsRejected(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidEntityAssignmentStrategyException::class);

       EntityAssignmentStrategy::mapped('location', ['a' => 1], UnknownEntityPolicy::USE_DEFAULT, null);
   }
}
