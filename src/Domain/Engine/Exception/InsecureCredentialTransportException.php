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

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * Raised when UXConnector would send reusable credentials over clear-text HTTP.
 */
final class InsecureCredentialTransportException extends \RuntimeException
{
    public static function forEndpoint(AuthMethod $authMethod, Endpoint $endpoint): self
    {
        return new self(sprintf(
            'Authentication method "%s" requires HTTPS; refusing to send credentials to insecure endpoint "%s".',
            $authMethod->value,
            $endpoint->value(),
        ));
    }
}
