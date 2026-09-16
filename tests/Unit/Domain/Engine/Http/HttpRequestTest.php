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

use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpRequest::class)]
final class HttpRequestTest extends TestCase
{
   public function testValidRequestIsAccepted(): void {
       $request = new HttpRequest(
           HttpMethod::POST,
           '/users',
           ['Content-Type' => 'application/json'],
           ['page' => '1'],
           '{"name":"test"}'
       );

       self::assertSame(HttpMethod::POST, $request->method);
       self::assertSame('/users', $request->path);
       self::assertSame('{"name":"test"}', $request->body);
   }

   public function testEmptyPathIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new HttpRequest(HttpMethod::GET, '   ');
   }

   public function testGetRequestWithBodyIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new HttpRequest(HttpMethod::GET, '/users', body: '{"x":1}');
   }

   public function testWithHeaderReturnsANewInstanceWithoutMutatingTheOriginal(): void {
       $original = new HttpRequest(HttpMethod::GET, '/users');
       $withAuth = $original->withHeader('Authorization', 'Bearer xyz');

       self::assertSame([], $original->headers);
       self::assertSame(['Authorization' => 'Bearer xyz'], $withAuth->headers);
       self::assertNotSame($original, $withAuth);
   }

   public function testHeaderValueContainingNewlineIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       (new HttpRequest(HttpMethod::GET, '/users'))
           ->withHeader('Authorization', "Bearer safe\r\nX-Evil: injected");
   }

   public function testInvalidHeaderNameIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new HttpRequest(HttpMethod::GET, '/users', ['Bad Header' => 'value']);
   }

   public function testPathContainingNewlineIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new HttpRequest(HttpMethod::GET, "/users\r\nX-Evil: injected");
   }

   public function testWithHeaderOverridesAnExistingHeaderOfTheSameName(): void {
       $request = (new HttpRequest(HttpMethod::GET, '/users'))
           ->withHeader('X-Api-Key', 'first')
           ->withHeader('X-Api-Key', 'second');

       self::assertSame('second', $request->headers['X-Api-Key']);
   }
}
