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
 * Raised when a CreateAuthConfigCommand targets an AuthMethod that
 * requires vaulted credentials (see AuthMethod::requiresVaultedCredentials())
 * but no plaintext secret was supplied.
 */
final class MissingCredentialException extends \InvalidArgumentException
{
    public static function forAuthMethod(AuthMethod $authMethod): self
    {
        return new self(sprintf(
            'Authentication method "%s" requires a credential, but none was provided.',
            $authMethod->value,
        ));
    }
}
