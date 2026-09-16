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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\Engine\Http\Authentication;

use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\ApiKeyAuthenticationStrategy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\BasicAuthenticationStrategy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\BearerTokenAuthenticationStrategy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\NoneAuthenticationStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoneAuthenticationStrategy::class)]
#[CoversClass(BasicAuthenticationStrategy::class)]
#[CoversClass(ApiKeyAuthenticationStrategy::class)]
#[CoversClass(BearerTokenAuthenticationStrategy::class)]
final class AuthenticationStrategyTest extends TestCase
{
   private function makeRequest(): HttpRequest {
       return new HttpRequest(HttpMethod::GET, '/users');
   }

   public function testNoneStrategyLeavesRequestUnchanged(): void {
       $request = $this->makeRequest();

       $result = (new NoneAuthenticationStrategy())->authenticate($request);

       self::assertSame($request, $result);
       self::assertSame([], $result->headers);
   }

   public function testBasicStrategyAddsBase64EncodedAuthorizationHeader(): void {
       $result = (new BasicAuthenticationStrategy('alice:s3cret'))
           ->authenticate($this->makeRequest());

       self::assertSame(
           'Basic ' . base64_encode('alice:s3cret'),
           $result->headers['Authorization']
       );
   }

   public function testApiKeyStrategyUsesDefaultHeaderName(): void {
       $result = (new ApiKeyAuthenticationStrategy('sk-1234'))
           ->authenticate($this->makeRequest());

       self::assertSame('sk-1234', $result->headers['X-Api-Key']);
   }

   public function testApiKeyStrategyAcceptsACustomHeaderName(): void {
       $result = (new ApiKeyAuthenticationStrategy('sk-1234', 'Ocp-Apim-Subscription-Key'))
           ->authenticate($this->makeRequest());

       self::assertSame('sk-1234', $result->headers['Ocp-Apim-Subscription-Key']);
       self::assertArrayNotHasKey('X-Api-Key', $result->headers);
   }

   public function testBearerTokenStrategyAddsAuthorizationHeader(): void {
       $result = (new BearerTokenAuthenticationStrategy('abc.def.ghi'))
           ->authenticate($this->makeRequest());

       self::assertSame('Bearer abc.def.ghi', $result->headers['Authorization']);
   }
}
