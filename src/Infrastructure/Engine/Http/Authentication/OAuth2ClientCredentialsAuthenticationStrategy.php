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

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication;

use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;

/**
 * OAuth2 Client Credentials authentication (RFC 6749 §4.4) : obtains
 * a fresh access token from $tokenUrl via OAuth2TokenClient on every
 * authenticate() call, then applies it as a standard Bearer header —
 * unlike BearerTokenAuthenticationStrategy's static, admin-supplied
 * token, this one is dynamically obtained using a client_id/client_secret
 * pair.
 */
final class OAuth2ClientCredentialsAuthenticationStrategy implements AuthenticationStrategy
{
   public function __construct(
        private readonly OAuth2TokenClient $tokenClient,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $scope
    ) {
   }

   public function authenticate(HttpRequest $request): HttpRequest {
       $token = $this->tokenClient->fetchClientCredentialsToken(
           $this->tokenUrl,
           $this->clientId,
           $this->clientSecret,
           $this->scope
       );

       return $request->withHeader('Authorization', 'Bearer ' . $token);
   }
}
