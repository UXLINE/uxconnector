<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonPathValueReader::class)]
final class JsonPathValueReaderTest extends TestCase
{
   public function testReadsNestedObjectField(): void {
       $reader = new JsonPathValueReader();
       $payload = ['fields' => ['status' => ['name' => 'In Progress']]];

       self::assertSame('In Progress', $reader->read($payload, 'fields.status.name'));
       self::assertSame('In Progress', $reader->read($payload, '$.fields.status.name'));
   }

   public function testReadsArrayIndex(): void {
       $reader = new JsonPathValueReader();
       self::assertSame('a.txt', $reader->read(['attachments' => [['filename' => 'a.txt']]], 'attachments[0].filename'));
   }

   public function testReadsQuotedBracketKeyContainingDot(): void {
       $reader = new JsonPathValueReader();
       self::assertSame('/records?page=2', $reader->read(['page.next-link' => '/records?page=2'], "$['page.next-link']"));
   }

   public function testMissingPathReturnsNull(): void {
       self::assertNull((new JsonPathValueReader())->read(['fields' => []], 'fields.summary'));
   }

   public function testLiteralIsTypedWhenItIsAJsonScalar(): void {
       $reader = new JsonPathValueReader();
       self::assertSame(1, $reader->read([], '@literal:1'));
       self::assertTrue($reader->read([], '@literal:true'));
       self::assertSame('Incident', $reader->read([], '@literal:Incident'));
   }
}
