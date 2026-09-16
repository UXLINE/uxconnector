<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\PaginationType;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\PaginationConfigRequestParser;
use PHPUnit\Framework\TestCase;

final class PaginationConfigRequestParserTest extends TestCase
{
   public function testEmptyRequestCreatesNoPagination(): void {
       $config = (new PaginationConfigRequestParser())->parse([]);

       self::assertSame(PaginationType::NONE, $config->type());
       self::assertSame([], $config->options());
   }

   public function testCursorFieldsAreConvertedToGenericPaginationConfig(): void {
       $config = (new PaginationConfigRequestParser())->parse([
           'pagination_type' => 'cursor',
           'cursor_response_path' => 'page.next',
           'cursor_request_parameter' => 'cursor',
           'last_page_response_path' => 'page.last',
           // Must be ignored for a cursor strategy by PaginationConfig validation.
           'offset_response_path' => '',
       ]);

       self::assertSame(PaginationType::CURSOR, $config->type());
       self::assertSame('page.next', $config->options()['cursor_response_path']);
       self::assertSame('cursor', $config->options()['cursor_request_parameter']);
       self::assertSame('page.last', $config->options()['last_page_response_path']);
   }


   public function testCountOffsetFieldsAreConvertedToPaginationConfig(): void {
       $config = (new PaginationConfigRequestParser())->parse([
           'pagination_type' => 'count_offset',
           'offset_request_parameter' => 'start',
           'page_size' => '100',
       ]);

       self::assertSame(PaginationType::COUNT_OFFSET, $config->type());
       self::assertSame('start', $config->options()['offset_request_parameter']);
       self::assertSame('100', $config->options()['page_size']);
   }

   public function testNextLinkQuotedPathIsPreserved(): void {
       $config = (new PaginationConfigRequestParser())->parse([
           'pagination_type' => 'next_link',
           'next_link_response_path' => "$['page.next-link']",
       ]);

       self::assertSame(PaginationType::NEXT_LINK, $config->type());
       self::assertSame("$['page.next-link']", $config->options()['next_link_response_path']);
   }
}
