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

namespace GlpiPlugin\Uxconnector\Application\Engine\Exception;

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;

/**
 * Raised when configuration creation requests an unsupported authentication method.
 * Complements the form filter with server-side enforcement.
 */
final class AuthMethodNotSupportedException extends \InvalidArgumentException
{
    public static function forMethod(AuthMethod $authMethod): self
    {
        return new self(sprintf(
            'Authentication method "%s" is not yet supported by this plugin.',
            $authMethod->value,
        ));
    }
}
