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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Engine\Http;

use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpResponse::class)]
final class HttpResponseTest extends TestCase
{
   public function testIsSuccessfulForwardStatusCodes(): void {
       self::assertTrue((new HttpResponse(200, [], ''))->isSuccessful());
       self::assertTrue((new HttpResponse(204, [], ''))->isSuccessful());
       self::assertFalse((new HttpResponse(301, [], ''))->isSuccessful());
       self::assertFalse((new HttpResponse(404, [], ''))->isSuccessful());
   }

   public function testIsClientAndServerError(): void {
       self::assertTrue((new HttpResponse(404, [], ''))->isClientError());
       self::assertFalse((new HttpResponse(500, [], ''))->isClientError());

       self::assertTrue((new HttpResponse(503, [], ''))->isServerError());
       self::assertFalse((new HttpResponse(404, [], ''))->isServerError());
   }

   public function testHeaderLookupIsCaseInsensitive(): void {
       $response = new HttpResponse(200, ['Content-Type' => 'application/json'], '{}');

       self::assertSame('application/json', $response->header('content-type'));
       self::assertSame('application/json', $response->header('CONTENT-TYPE'));
       self::assertNull($response->header('X-Missing'));
   }

   public function testJsonDecodesAValidBody(): void {
       $response = new HttpResponse(200, [], '{"id":42,"name":"test"}');

       self::assertSame(['id' => 42, 'name' => 'test'], $response->json());
   }

   public function testJsonThrowsOnInvalidBody(): void {
       $response = new HttpResponse(200, [], 'not json');

       $this->expectException(\RuntimeException::class);

       $response->json();
   }
}
