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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Sync\Policy;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetFieldPolicy::class)]
final class TargetFieldPolicyTest extends TestCase
{
   public function testSafeComputerMappingIsAccepted(): void {
       (new TargetFieldPolicy())->assertConfiguration(
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           [new FieldMapping('serialNumber', 'serial'), new FieldMapping('hostName', 'name')]
       );

       $this->addToAssertionCount(1);
   }

   public function testTechnicalFieldIsRejected(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\UnsafeTargetFieldException::class);

       (new TargetFieldPolicy())->assertConfiguration(
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           [new FieldMapping('deleted', 'is_deleted')]
       );
   }

   public function testEntitiesIdCannotBeMappedDirectly(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\UnsafeTargetFieldException::class);

       (new TargetFieldPolicy())->assertConfiguration(
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           [new FieldMapping('entity', 'entities_id')]
       );
   }

   public function testUnsafeMatchFieldIsRejected(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\UnsafeTargetFieldException::class);

       (new TargetFieldPolicy())->assertConfiguration(
           TargetItemtype::USER,
           new MatchField('password'),
           [new FieldMapping('login', 'name')]
       );
   }
   public function testJiraProjectMappingFieldsAreAccepted(): void {
       (new TargetFieldPolicy())->assertConfiguration(
           TargetItemtype::PROJECT,
           new MatchField('code'),
           [new FieldMapping('key', 'code'), new FieldMapping('name', 'name')]
       );
       $this->addToAssertionCount(1);
   }

   public function testJiraTicketMappingFieldsAreAccepted(): void {
       (new TargetFieldPolicy())->assertConfiguration(
           TargetItemtype::TICKET,
           new MatchField('externalid'),
           [
               new FieldMapping('key', 'externalid'),
               new FieldMapping('fields.summary', 'name'),
               new FieldMapping('fields.description', 'content'),
               new FieldMapping('@literal:1', 'type'),
           ]
       );
       $this->addToAssertionCount(1);
   }

}
