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

use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * Blocks a fixed, non-configurable list of network ranges that must
 * never be reachable from an AuthConfig, regardless of who configured
 * it: loopback, link-local (which includes the 169.254.169.254 cloud
 * instance metadata endpoint on AWS/Azure/GCP), and a couple of
 * related special-purpose ranges.
 *
 * Deliberately does NOT block RFC1918 private ranges (10.0.0.0/8,
 * 172.16.0.0/12, 192.168.0.0/16): UXConnector's entire purpose is to
 * reach internal systems (Zabbix, MDM, internal REST APIs) that
 * legitimately live there. A policy blocking all private networks
 * would break the product's core use case - see this class's
 * companion documentation in the project synthesis document, §6.6.
 *
 * The host is resolved to its actual IP address(es) before the check
 * (via HostResolverInterface) - checking the literal hostname string
 * alone would not catch a name that simply resolves to a forbidden
 * address.
 *
 * Résiduel documenté : cette vérification a lieu à un instant T. Un
 * hôte dont la résolution DNS change entre cette vérification et la
 * connexion réelle effectuée par GenericHttpEngine (DNS rebinding)
 * n'est pas protégé par cette classe seule - une protection complète
 * nécessiterait un « pinning » de l'adresse IP résolue jusqu'à
 * l'établissement effectif de la connexion, non implémenté ici.
 */
final class DestinationPolicy implements DestinationPolicyInterface
{
    /**
     * @var list<string>
     */
    private const BLOCKED_IPV4_CIDRS = [
        '127.0.0.0/8',    // loopback
        '0.0.0.0/8',      // "this network"
        '169.254.0.0/16', // link-local, includes cloud instance metadata (169.254.169.254)
        '100.64.0.0/10',  // carrier-grade NAT, used by some cloud metadata setups
    ];

    public function __construct(
        private readonly HostResolverInterface $resolver
    ) {
    }

    public function assertAllowed(Endpoint $endpoint): void
    {
        $host = parse_url($endpoint->value(), PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::unresolvableHost(
                $endpoint->value()
            );
        }

        // parse_url() keeps the surrounding brackets for an IPv6
        // literal host (e.g. "[::1]") - strip them before treating
        // the value as a candidate IP address.
        $host = trim($host, '[]');

        $this->assertHostAllowlist($host);

        $ips = $this->resolveHost($host);

        if ($ips === []) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::unresolvableHost(
                $host
            );
        }

        foreach ($ips as $ip) {
            if ($this->isBlocked($ip)) {
                throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::blockedAddress(
                    $host,
                    $ip
                );
            }
        }
    }

    private function assertHostAllowlist(string $host): void
    {
        $raw = getenv('UXCONNECTOR_ALLOWED_HOSTS');
        if ($raw === false || trim($raw) === '') {
            return;
        }

        $host = strtolower(rtrim($host, '.'));
        $patterns = array_values(array_filter(array_map(
            static fn(string $value): string => strtolower(rtrim(trim($value), '.')),
            explode(',', $raw),
        ), static fn(string $value): bool => $value !== ''));

        foreach ($patterns as $pattern) {
            if ($pattern === $host) {
                return;
            }

            if (str_starts_with($pattern, '*.') && strlen($pattern) > 2) {
                $suffix = substr($pattern, 1);
                if (str_ends_with($host, $suffix) && $host !== substr($suffix, 1)) {
                    return;
                }
            }
        }

        throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::hostNotAllowlisted($host);
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        // A literal IP address needs no DNS resolution.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->resolver->resolve($host);
    }

    private function isBlocked(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            return $this->isBlockedIpv6($ip);
        }

        return $this->isBlockedIpv4($ip);
    }

    private function isBlockedIpv4(string $ip): bool
    {
        foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
            if ($this->ipv4InCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function isBlockedIpv6(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            // Malformed input we cannot classify: fail closed rather
            // than let an unrecognized address format slip through.
            return true;
        }

        if ($ip === '::1' || $ip === '::') {
            return true;
        }

        if ($this->ipv6InPrefix($packed, 'fe80::', 10)) {
            return true;
        }

        // IPv4-mapped addresses must be recognized from their binary form,
        // not from the textual suffix. IPv6 permits hexadecimal spellings such
        // as ::ffff:7f00:1, which maps to 127.0.0.1 but is not itself a valid
        // dotted IPv4 string. Text-only parsing would therefore create an SSRF
        // bypass around the loopback/link-local policy.
        $mappedPrefix = hex2bin('00000000000000000000ffff');

        if ($mappedPrefix !== false && substr($packed, 0, 12) === $mappedPrefix) {
            $mapped = inet_ntop(substr($packed, 12, 4));

            return $mapped === false || $this->isBlockedIpv4($mapped);
        }

        return false;
    }

    private function ipv6InPrefix(string $packed, string $prefixAddress, int $prefixBits): bool
    {
        $prefixPacked = @inet_pton($prefixAddress);

        if ($prefixPacked === false) {
            return false;
        }

        $fullBytes = intdiv($prefixBits, 8);
        $remainingBits = $prefixBits % 8;

        if (substr($packed, 0, $fullBytes) !== substr($prefixPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($prefixPacked[$fullBytes]) & $mask);
    }
}
