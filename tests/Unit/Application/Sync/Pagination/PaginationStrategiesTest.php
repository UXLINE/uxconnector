<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\Pagination;

use GlpiPlugin\Uxconnector\Application\Sync\Pagination\PaginationStrategyResolver;
use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;
use PHPUnit\Framework\TestCase;

final class PaginationStrategiesTest extends TestCase
{
   public function testCursorStrategyUsesConfiguredPathsOnly(): void {
       $strategy = (new PaginationStrategyResolver(new JsonPathValueReader()))->resolve(
           PaginationConfig::fromArray('cursor', [
               'cursor_response_path' => 'page.next',
               'cursor_request_parameter' => 'cursor',
               'last_page_response_path' => 'page.last',
           ])
       );

       self::assertSame(
           '/records?cursor=abc%2B123',
           $strategy->nextPageReference('/records', ['page' => ['last' => false, 'next' => 'abc+123']], 50)
       );
       self::assertNull($strategy->nextPageReference('/records', ['page' => ['last' => true]], 10));
   }

   public function testOffsetStrategyUsesConfiguredPathsOnly(): void {
       $strategy = (new PaginationStrategyResolver(new JsonPathValueReader()))->resolve(
           PaginationConfig::fromArray('offset', [
               'offset_response_path' => 'meta.start',
               'offset_request_parameter' => 'offset',
               'page_size_response_path' => 'meta.limit',
               'total_response_path' => 'meta.total',
           ])
       );

       self::assertSame(
           '/records?limit=25&offset=25',
           $strategy->nextPageReference(
               '/records?limit=25',
               ['meta' => ['start' => 0, 'limit' => 25, 'total' => 40]],
               25
           )
       );
   }


   public function testCountOffsetStrategyAdvancesUsingReturnedItemCount(): void {
       $strategy = (new PaginationStrategyResolver(new JsonPathValueReader()))->resolve(
           PaginationConfig::fromArray('count_offset', [
               'offset_request_parameter' => 'start',
               'page_size' => '100',
           ])
       );

       self::assertSame(
           '/Assets/Computer?start=100&limit=100',
           $strategy->nextPageReference('/Assets/Computer?start=0&limit=100', [], 100)
       );
       self::assertSame(
           '/Assets/Computer?start=200&limit=100',
           $strategy->nextPageReference('/Assets/Computer?start=100&limit=100', [], 100)
       );
       self::assertNull(
           $strategy->nextPageReference('/Assets/Computer?start=200&limit=100', [], 37)
       );
   }

   public function testNextLinkStrategyReadsQuotedKeyPath(): void {
       $strategy = (new PaginationStrategyResolver(new JsonPathValueReader()))->resolve(
           PaginationConfig::fromArray('next_link', [
               'next_link_response_path' => "$['page.next-link']",
           ])
       );

       self::assertSame(
           'https://api.example.com/records?page=2',
           $strategy->nextPageReference(
               '/records',
               ['page.next-link' => 'https://api.example.com/records?page=2'],
               100
           )
       );
   }
}
