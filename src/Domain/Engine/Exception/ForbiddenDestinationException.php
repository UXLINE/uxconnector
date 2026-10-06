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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Exception;

final class ForbiddenDestinationException extends \RuntimeException
{
    public static function blockedAddress(string $host, string $resolvedIp): self
    {
        return new self(sprintf(
            'Destination "%s" resolves to a forbidden address (%s). ' .
            'Loopback, link-local and cloud metadata addresses can never ' .
            'be contacted by this plugin.',
            $host,
            $resolvedIp
        ));
    }

    public static function unresolvableHost(string $host): self
    {
        return new self(sprintf(
            'Destination host "%s" could not be resolved to any address.',
            $host
        ));
    }

    public static function hostNotAllowlisted(string $host): self
    {
        return new self(sprintf(
            'Destination host "%s" is not present in the configured UXConnector host allowlist.',
            $host
        ));
    }
}
