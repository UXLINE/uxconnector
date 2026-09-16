<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Service\CollectionExtractor;
use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CollectionExtractor::class)]
final class CollectionExtractorTest extends TestCase
{
   public function testExtractsConfiguredNestedCollection(): void {
       $extractor = new CollectionExtractor(new JsonPathValueReader());
       $payload = ['payload' => ['records' => [['id' => 1], ['id' => 2]]]];

       self::assertSame(
           [['id' => 1], ['id' => 2]],
           $extractor->extract($payload, new CollectionPath('payload.records'))
       );
   }

   public function testRootCollectionRemainsSupported(): void {
       $extractor = new CollectionExtractor(new JsonPathValueReader());
       $payload = [['id' => 1]];

       self::assertSame($payload, $extractor->extract($payload, CollectionPath::root()));
   }

   public function testConfiguredPathMustResolveToAList(): void {
       $this->expectException(\RuntimeException::class);

       (new CollectionExtractor(new JsonPathValueReader()))->extract(
           ['payload' => ['record' => ['id' => 1]]],
           new CollectionPath('payload.record')
       );
   }
}
