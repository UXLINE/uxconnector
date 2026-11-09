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
 * Resolves a hostname via the system's actual DNS resolution
 * (dns_get_record(), with a fallback to gethostbynamel() for
 * environments/records dns_get_record() does not cover).
 */
final class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $hostname): array
    {
        $ips = [];

        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $legacy = @gethostbynamel($hostname);

            if (is_array($legacy)) {
                $ips = $legacy;
            }
        }

        return array_values(array_unique($ips));
    }
}
