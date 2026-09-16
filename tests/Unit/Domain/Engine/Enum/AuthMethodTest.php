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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Engine\Enum;

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthMethod::class)]
final class AuthMethodTest extends TestCase
{
   public function testNoneDoesNotRequireVaultedCredentials(): void {
       self::assertFalse(AuthMethod::NONE->requiresVaultedCredentials());
   }

   public function testBasicRequiresVaultedCredentials(): void {
       self::assertTrue(AuthMethod::BASIC->requiresVaultedCredentials());
   }

   public function testOAuth2MethodsAreRecognized(): void {
       self::assertTrue(AuthMethod::OAUTH2_CLIENT_CREDENTIALS->isOAuth2());
       self::assertTrue(AuthMethod::OAUTH2_AUTHORIZATION_CODE->isOAuth2());
       self::assertFalse(AuthMethod::API_KEY->isOAuth2());
       self::assertFalse(AuthMethod::NONE->isOAuth2());
   }

   public function testEveryCaseHasANonEmptyLabel(): void {
      foreach (AuthMethod::cases() as $case) {
          self::assertNotSame('', $case->label());
      }
   }

   public function testOnlyAuthorizationCodeRemainsUnimplemented(): void {
       self::assertFalse(AuthMethod::OAUTH2_AUTHORIZATION_CODE->isImplemented());
   }

   public function testClientCredentialsAndNonOAuth2MethodsAreImplemented(): void {
       self::assertTrue(AuthMethod::NONE->isImplemented());
       self::assertTrue(AuthMethod::BASIC->isImplemented());
       self::assertTrue(AuthMethod::API_KEY->isImplemented());
       self::assertTrue(AuthMethod::BEARER_TOKEN->isImplemented());
       self::assertTrue(AuthMethod::OAUTH2_CLIENT_CREDENTIALS->isImplemented());
   }
}
