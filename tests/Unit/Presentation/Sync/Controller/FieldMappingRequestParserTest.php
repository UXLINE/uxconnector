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

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\FieldMappingRequestParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldMappingRequestParser::class)]
final class FieldMappingRequestParserTest extends TestCase
{
   public function testParsesACompleteRow(): void {
       $parser = new FieldMappingRequestParser();

       $result = $parser->parse([
           'source_field' => ['serialNumber'],
           'target_field' => ['serial'],
           'transform'    => ['trim'],
           'required'     => [0 => '1'],
       ]);

       self::assertCount(1, $result);
       self::assertInstanceOf(FieldMapping::class, $result[0]);
       self::assertSame('serialNumber', $result[0]->sourceField());
       self::assertSame('serial', $result[0]->targetField());
       self::assertSame('trim', $result[0]->transform());
       self::assertTrue($result[0]->isRequired());
   }

   public function testFullyBlankRowsAreSkipped(): void {
       $parser = new FieldMappingRequestParser();

       $result = $parser->parse([
           'source_field' => ['', 'name'],
           'target_field' => ['', 'name'],
       ]);

       self::assertCount(1, $result);
       self::assertSame('name', $result[0]->sourceField());
   }

   public function testIncompleteRowIsRejected(): void {
       $parser = new FieldMappingRequestParser();
       $this->expectException(\InvalidArgumentException::class);

       $parser->parse([
           'source_field' => ['mail'],
           'target_field' => [''],
       ]);
   }

   public function testTrimsWhitespaceFromFieldNames(): void {
       $parser = new FieldMappingRequestParser();

       $result = $parser->parse([
           'source_field' => ['  mail  '],
           'target_field' => ['  email1  '],
       ]);

       self::assertSame('mail', $result[0]->sourceField());
       self::assertSame('email1', $result[0]->targetField());
   }

   public function testEmptyTransformBecomesNull(): void {
       $parser = new FieldMappingRequestParser();

       $result = $parser->parse([
           'source_field' => ['mail'],
           'target_field' => ['email1'],
           'transform'    => [''],
       ]);

       self::assertNull($result[0]->transform());
   }

   public function testMissingRequiredKeyMeansNotRequired(): void {
       $parser = new FieldMappingRequestParser();

       $result = $parser->parse([
           'source_field' => ['mail'],
           'target_field' => ['email1'],
       ]);

       self::assertFalse($result[0]->isRequired());
   }

   public function testUnsupportedTransformIsRejectedAtConfigurationTime(): void {
       $parser = new FieldMappingRequestParser();
       $this->expectException(\InvalidArgumentException::class);

       $parser->parse([
           'source_field' => ['name'],
           'target_field' => ['name'],
           'transform' => ['reverse'],
       ]);
   }

   public function testEmptyRequestReturnsEmptyList(): void {
       $parser = new FieldMappingRequestParser();

       self::assertSame([], $parser->parse([]));
   }

   public function testMultipleValidRowsArePreservedInOrder(): void {
       $parser = new FieldMappingRequestParser();

       $result = $parser->parse([
           'source_field' => ['serialNumber', 'mail'],
           'target_field' => ['serial', 'email1'],
           'required'     => [0 => '1'],
       ]);

       self::assertCount(2, $result);
       self::assertSame('serialNumber', $result[0]->sourceField());
       self::assertTrue($result[0]->isRequired());
       self::assertSame('mail', $result[1]->sourceField());
       self::assertFalse($result[1]->isRequired());
   }
   public function testConfigurableMapTransformIsAccepted(): void {
       $result = (new FieldMappingRequestParser())->parse([
           'source_field' => ['fields.priority.name'],
           'target_field' => ['priority'],
           'transform' => ['map:Highest=6|High=5|*=3'],
       ]);

       self::assertSame('map:Highest=6|High=5|*=3', $result[0]->transform());
   }

}
