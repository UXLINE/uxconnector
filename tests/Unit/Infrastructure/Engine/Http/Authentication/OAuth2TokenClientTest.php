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

use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\OAuth2TokenClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuth2TokenClient::class)]
final class OAuth2TokenClientTest extends TestCase
{
   public function testClearTextTokenEndpointIsRejectedBeforeDestinationLookup(): void {
       $policy = new class implements DestinationPolicyInterface {
         public bool $called = false;

         public function assertAllowed(Endpoint $endpoint): void {
            $this->called = true;
         }
       };

         $client = new OAuth2TokenClient($policy);

         $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::class);

      try {
         $client->fetchClientCredentialsToken(
             'http://idp.internal.local/token',
             'client-id',
             'client-secret',
             null
          );
      } finally {
         self::assertFalse($policy->called);
      }
   }
}
