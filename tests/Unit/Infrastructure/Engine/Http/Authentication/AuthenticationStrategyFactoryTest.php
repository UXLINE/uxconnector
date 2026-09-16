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

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\ApiKeyAuthenticationStrategy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\AuthenticationStrategyFactory;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\BasicAuthenticationStrategy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\BearerTokenAuthenticationStrategy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\NoneAuthenticationStrategy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\OAuth2ClientCredentialsAuthenticationStrategy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\OAuth2TokenClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthenticationStrategyFactory::class)]
final class AuthenticationStrategyFactoryTest extends TestCase
{
   private function makeFactory(): AuthenticationStrategyFactory {
       $permissivePolicy = new class implements DestinationPolicyInterface {
         public function assertAllowed(Endpoint $endpoint): void {
         }
       };

         return new AuthenticationStrategyFactory(new OAuth2TokenClient($permissivePolicy));
   }

   public function testNoneDoesNotRequireASecret(): void {
       $strategy = $this->makeFactory()->create(AuthMethod::NONE, null);

       self::assertInstanceOf(NoneAuthenticationStrategy::class, $strategy);
   }

   public function testBasicResolvesToBasicStrategy(): void {
       $strategy = $this->makeFactory()->create(AuthMethod::BASIC, 'user:pass');

       self::assertInstanceOf(BasicAuthenticationStrategy::class, $strategy);
   }

   public function testApiKeyResolvesToApiKeyStrategy(): void {
       $strategy = $this->makeFactory()->create(AuthMethod::API_KEY, 'sk-1234');

       self::assertInstanceOf(ApiKeyAuthenticationStrategy::class, $strategy);
   }

   public function testBearerTokenResolvesToBearerTokenStrategy(): void {
       $strategy = $this->makeFactory()->create(AuthMethod::BEARER_TOKEN, 'abc');

       self::assertInstanceOf(BearerTokenAuthenticationStrategy::class, $strategy);
   }

   public function testMissingSecretForBasicIsRejected(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::class);

       $this->makeFactory()->create(AuthMethod::BASIC, null);
   }

   public function testEmptySecretForApiKeyIsRejected(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::class);

       $this->makeFactory()->create(AuthMethod::API_KEY, '');
   }

   public function testOAuth2ClientCredentialsResolvesToItsStrategyWithAValidJsonSecret(): void {
       $secret = json_encode([
           'token_url' => 'https://login.example.com/oauth2/token',
           'client_id' => 'my-client',
           'client_secret' => 'my-secret',
           'scope' => 'api.read',
       ], JSON_THROW_ON_ERROR);

       $strategy = $this->makeFactory()->create(AuthMethod::OAUTH2_CLIENT_CREDENTIALS, $secret);

       self::assertInstanceOf(OAuth2ClientCredentialsAuthenticationStrategy::class, $strategy);
   }

   public function testOAuth2ClientCredentialsWorksWithoutAnOptionalScope(): void {
       $secret = json_encode([
           'token_url' => 'https://login.example.com/oauth2/token',
           'client_id' => 'my-client',
           'client_secret' => 'my-secret',
       ], JSON_THROW_ON_ERROR);

       $strategy = $this->makeFactory()->create(AuthMethod::OAUTH2_CLIENT_CREDENTIALS, $secret);

       self::assertInstanceOf(OAuth2ClientCredentialsAuthenticationStrategy::class, $strategy);
   }

   public function testOAuth2ClientCredentialsRejectsNonJsonSecret(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::class);

       $this->makeFactory()->create(AuthMethod::OAUTH2_CLIENT_CREDENTIALS, 'not-json-at-all');
   }

   public function testOAuth2ClientCredentialsRejectsJsonMissingRequiredKeys(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::class);

       $this->makeFactory()->create(
           AuthMethod::OAUTH2_CLIENT_CREDENTIALS,
           json_encode(['token_url' => 'https://login.example.com/token'], JSON_THROW_ON_ERROR)
       );
   }

   public function testOAuth2AuthorizationCodeRemainsUnsupported(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::class);

       $this->makeFactory()->create(
           AuthMethod::OAUTH2_AUTHORIZATION_CODE,
           'whatever'
       );
   }
}
