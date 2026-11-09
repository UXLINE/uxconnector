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
 * Applies one AuthMethod's authentication scheme to an outgoing
 * HttpRequest. Each implementation handles exactly one AuthMethod
 * (Strategy pattern) and receives its already-decrypted secret (if
 * any) at construction time - it never touches the CredentialVault
 * or the AuthConfigSecretStore itself; that resolution happens once,
 * in GenericHttpEngine, before the strategy is built.
 */
interface AuthenticationStrategy
{
    public function authenticate(HttpRequest $request): HttpRequest;
}
