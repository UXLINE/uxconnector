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
 * HTTP Basic authentication. The credential stored in the
 * CredentialVault for this AuthMethod is expected to be the raw
 * "username:password" string (never pre-base64-encoded - encoding is
 * a wire-format detail, not part of the secret itself).
 */
final class BasicAuthenticationStrategy implements AuthenticationStrategy
{
    public function __construct(
        private readonly string $usernameAndPassword
    ) {}

    public function authenticate(HttpRequest $request): HttpRequest
    {
        return $request->withHeader(
            'Authorization',
            'Basic ' . base64_encode($this->usernameAndPassword),
        );
    }
}
