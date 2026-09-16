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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\UnknownEntityPolicy;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\EntityAssignmentStrategyRequestParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityAssignmentStrategyRequestParser::class)]
final class EntityAssignmentStrategyRequestParserTest extends TestCase
{
   public function testNoStrategySelectedReturnsNull(): void {
       $parser = new EntityAssignmentStrategyRequestParser();

       self::assertNull($parser->parse(['entity_strategy' => '']));
       self::assertNull($parser->parse([]));
   }

   public function testFixedStrategyIsParsedCorrectly(): void {
       $parser = new EntityAssignmentStrategyRequestParser();

       $strategy = $parser->parse(['entity_strategy' => 'fixed', 'entity_fixed_id' => '42']);

       self::assertNotNull($strategy);
       self::assertTrue($strategy->isFixed());
       self::assertSame(42, $strategy->fixedEntityId());
   }

   public function testFixedStrategyWithNonNumericIdIsRejected(): void {
       $parser = new EntityAssignmentStrategyRequestParser();
       $this->expectException(\InvalidArgumentException::class);

       $parser->parse(['entity_strategy' => 'fixed', 'entity_fixed_id' => 'not-a-number']);
   }

   public function testMappedStrategyTrimsValidRules(): void {
       $parser = new EntityAssignmentStrategyRequestParser();

       $strategy = $parser->parse([
           'entity_strategy' => 'mapped',
           'entity_source_field' => 'location',
           'entity_mapping_rules' => "Nantes=42\nParis=68\n\n  Lyon = 91  ",
           'entity_unknown_policy' => 'use_default',
           'entity_default_id' => '0',
       ]);

       self::assertNotNull($strategy);
       self::assertSame('location', $strategy->sourceField());
       self::assertSame(['Nantes' => 42, 'Paris' => 68, 'Lyon' => 91], $strategy->valueToEntityId());
       self::assertSame(UnknownEntityPolicy::USE_DEFAULT, $strategy->unknownPolicy());
       self::assertSame(0, $strategy->defaultEntityId());
   }

   public function testMalformedMappedRuleIsRejected(): void {
       $parser = new EntityAssignmentStrategyRequestParser();
       $this->expectException(\InvalidArgumentException::class);

       $parser->parse([
           'entity_strategy' => 'mapped',
           'entity_source_field' => 'location',
           'entity_mapping_rules' => "Nantes=42\ngarbage-line",
       ]);
   }

   public function testMappedStrategyWithNoValidRulesIsRejected(): void {
       $parser = new EntityAssignmentStrategyRequestParser();
       $this->expectException(\InvalidArgumentException::class);

       $parser->parse([
           'entity_strategy' => 'mapped',
           'entity_source_field' => 'location',
           'entity_mapping_rules' => "garbage\nmore garbage",
       ]);
   }

   public function testMappedStrategyWithUseDefaultButNoUsableDefaultIdIsRejected(): void {
       $parser = new EntityAssignmentStrategyRequestParser();
       $this->expectException(\InvalidArgumentException::class);

       $parser->parse([
           'entity_strategy' => 'mapped',
           'entity_source_field' => 'location',
           'entity_mapping_rules' => 'Nantes=42',
           'entity_unknown_policy' => 'use_default',
           'entity_default_id' => '',
       ]);
   }

   public function testMappedStrategyWithoutASourceFieldIsRejected(): void {
       $parser = new EntityAssignmentStrategyRequestParser();
       $this->expectException(\InvalidArgumentException::class);

       $parser->parse([
           'entity_strategy' => 'mapped',
           'entity_source_field' => '',
           'entity_mapping_rules' => 'Nantes=42',
       ]);
   }
}
