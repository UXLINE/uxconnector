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
 * API key authentication via a request header.
 *
 * The header name defaults to "X-Api-Key", the most common
 * convention. Making it configurable per AuthConfig (some APIs use
 * "Ocp-Apim-Subscription-Key", "apikey", a query parameter, ...) is
 * intentionally left for a future commit — see this plugin's REX
 * discipline of not building configurability ahead of a concrete
 * need (AI-HOOKMANAGER-004, applied here by analogy).
 */
final class ApiKeyAuthenticationStrategy implements AuthenticationStrategy
{
   private const DEFAULT_HEADER_NAME = 'X-Api-Key';

   public function __construct(
        private readonly string $apiKey,
        private readonly string $headerName = self::DEFAULT_HEADER_NAME
    ) {
   }

   public function authenticate(HttpRequest $request): HttpRequest {
       return $request->withHeader($this->headerName, $this->apiKey);
   }
}
