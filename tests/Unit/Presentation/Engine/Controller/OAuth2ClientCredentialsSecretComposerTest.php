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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Presentation\Engine\Controller;

use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\OAuth2ClientCredentialsSecretComposer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuth2ClientCredentialsSecretComposer::class)]
final class OAuth2ClientCredentialsSecretComposerTest extends TestCase
{
   public function testComposesAValidJsonPayloadWithAllFields(): void {
       $composer = new OAuth2ClientCredentialsSecretComposer();

       $secret = $composer->compose([
           'oauth2_token_url' => 'https://login.example.com/oauth2/token',
           'oauth2_client_id' => 'my-client',
           'oauth2_client_secret' => 'my-secret',
           'oauth2_scope' => 'api.read api.write',
       ]);

       self::assertNotNull($secret);
       $decoded = json_decode($secret, true);
       self::assertSame('https://login.example.com/oauth2/token', $decoded['token_url']);
       self::assertSame('my-client', $decoded['client_id']);
       self::assertSame('my-secret', $decoded['client_secret']);
       self::assertSame('api.read api.write', $decoded['scope']);
   }

   public function testScopeIsOmittedWhenNotProvided(): void {
       $composer = new OAuth2ClientCredentialsSecretComposer();

       $secret = $composer->compose([
           'oauth2_token_url' => 'https://login.example.com/oauth2/token',
           'oauth2_client_id' => 'my-client',
           'oauth2_client_secret' => 'my-secret',
       ]);

       $decoded = json_decode($secret, true);
       self::assertArrayNotHasKey('scope', $decoded);
   }

   public function testReturnsNullWhenTokenUrlIsMissing(): void {
       $composer = new OAuth2ClientCredentialsSecretComposer();

       self::assertNull($composer->compose([
           'oauth2_client_id' => 'my-client',
           'oauth2_client_secret' => 'my-secret',
       ]));
   }

   public function testReturnsNullWhenClientIdIsMissing(): void {
       $composer = new OAuth2ClientCredentialsSecretComposer();

       self::assertNull($composer->compose([
           'oauth2_token_url' => 'https://login.example.com/oauth2/token',
           'oauth2_client_secret' => 'my-secret',
       ]));
   }

   public function testReturnsNullWhenClientSecretIsMissing(): void {
       $composer = new OAuth2ClientCredentialsSecretComposer();

       self::assertNull($composer->compose([
           'oauth2_token_url' => 'https://login.example.com/oauth2/token',
           'oauth2_client_id' => 'my-client',
       ]));
   }

   public function testReturnsNullForAnEmptyRequest(): void {
       $composer = new OAuth2ClientCredentialsSecretComposer();

       self::assertNull($composer->compose([]));
   }

   public function testTrimsWhitespaceFromAllFields(): void {
       $composer = new OAuth2ClientCredentialsSecretComposer();

       $secret = $composer->compose([
           'oauth2_token_url' => '  https://login.example.com/oauth2/token  ',
           'oauth2_client_id' => '  my-client  ',
           'oauth2_client_secret' => '  my-secret  ',
       ]);

       $decoded = json_decode($secret, true);
       self::assertSame('https://login.example.com/oauth2/token', $decoded['token_url']);
       self::assertSame('my-client', $decoded['client_id']);
       self::assertSame('my-secret', $decoded['client_secret']);
   }
}
