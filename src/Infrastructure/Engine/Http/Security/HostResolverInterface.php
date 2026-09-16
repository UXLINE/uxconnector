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

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security;

/**
 * Resolves a hostname to its actual IP address(es).
 *
 * Isolated behind an interface so DestinationPolicy's blocking logic
 * (the part that actually matters for tests) can be unit-tested with
 * a fake resolver, without depending on real DNS resolution — which
 * this sandbox's egress proxy would refuse for arbitrary hostnames
 * anyway.
 */
interface HostResolverInterface
{
    /**
     * @return list<string> Resolved IPv4/IPv6 addresses, or an empty
     *         array if resolution failed.
     */
   public function resolve(string $hostname): array;
}
