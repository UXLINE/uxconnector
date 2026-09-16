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

final class NoneAuthenticationStrategy implements AuthenticationStrategy
{
   public function authenticate(HttpRequest $request): HttpRequest {
       return $request;
   }
}
