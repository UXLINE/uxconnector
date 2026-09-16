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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Http;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException;

/**
 * Port through which the Application layer (e.g. a future
 * ExecuteSyncJobService in the Sync bounded context) sends an
 * authenticated HTTP request to the third-party system an AuthConfig
 * points to, without knowing:
 *
 * - which wire-level transport is used (cURL, PHP streams, ...) ;
 * - which AuthMethod the AuthConfig uses, or how it is applied ;
 * - that a secret exists at all, let alone how it is stored or
 *   decrypted.
 *
 * The concrete implementation (GenericHttpEngine, Infrastructure)
 * resolves the AuthConfig's credential through CredentialVaultInterface
 * and AuthConfigSecretStoreInterface internally.
 */
interface HttpEngineInterface
{
    /**
     * @throws HttpEngineException if the request could not be
     *         authenticated or sent (missing/undecryptable credential,
     *         unsupported AuthMethod, network/transport failure).
     *         A non-2xx HTTP response is NOT an exception: it is
     *         returned as a normal HttpResponse for the caller to
     *         inspect (RULE-CTRL-like separation: transport failure
     *         vs. business-level HTTP status are different concerns).
     */
   public function execute(AuthConfig $authConfig, HttpRequest $request): HttpResponse;
}
