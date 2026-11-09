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
 * Static bearer token authentication (as opposed to the OAuth2
 * strategies, which obtain and refresh their token dynamically -
 * not yet implemented, see AuthenticationStrategyFactory).
 */
final class BearerTokenAuthenticationStrategy implements AuthenticationStrategy
{
    public function __construct(
        private readonly string $token
    ) {}

    public function authenticate(HttpRequest $request): HttpRequest
    {
        return $request->withHeader('Authorization', 'Bearer ' . $this->token);
    }
}
