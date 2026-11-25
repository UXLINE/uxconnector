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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Security;

use GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * Destination policy shared by configuration validation and each outbound HTTP attempt.
 * URL syntax validation alone cannot prevent SSRF. Legitimate internal APIs may be
 * allowed by a dedicated policy, but loopback, metadata and other prohibited destinations
 * must be rejected before connection. Each transport uses the approved resolved addresses.
 */
interface DestinationPolicyInterface
{
    /**
     * @throws ForbiddenDestinationException if the endpoint's host
     *         resolves to an address that must never be contacted by
     *         this plugin, or cannot be resolved at all.
     */
    public function assertAllowed(Endpoint $endpoint): void;

    /**
     * Resolves and validates all addresses once for a transport connection.
     *
     * @return non-empty-list<string> Approved literal IP addresses, never hostnames.
     * @throws ForbiddenDestinationException If any address is forbidden or resolution fails.
     */
    public function resolveAllowed(Endpoint $endpoint): array;
}
