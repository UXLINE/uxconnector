<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication;

use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;

/** OAuth2 Resource Owner Password grant, required by GLPI 11 HL API for unattended user-bound API access. */
final class OAuth2PasswordAuthenticationStrategy implements AuthenticationStrategy
{
   public function __construct(
        private readonly OAuth2TokenClient $tokenClient,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $username,
        private readonly string $password,
        private readonly ?string $scope,
    ) {}

   public function authenticate(HttpRequest $request): HttpRequest {
       $token = $this->tokenClient->fetchPasswordToken(
           $this->tokenUrl,
           $this->clientId,
           $this->clientSecret,
           $this->username,
           $this->password,
           $this->scope,
       );
       return $request->withHeader('Authorization', 'Bearer ' . $token);
   }
}
