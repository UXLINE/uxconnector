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

/**
 * Raised by a HttpEngineInterface implementation when a request could
 * not be authenticated or sent. Never carries a plaintext secret in
 * its message.
 */
final class HttpEngineException extends \RuntimeException
{
    public static function unsupportedAuthMethod(AuthMethod $authMethod): self
    {
        return new self(sprintf(
            'Authentication method "%s" is not yet supported by GenericHttpEngine.',
            $authMethod->value,
        ));
    }

    public static function missingCredential(AuthMethod $authMethod): self
    {
        return new self(sprintf(
            'Authentication method "%s" requires a credential, but none is stored for this AuthConfig.',
            $authMethod->value,
        ));
    }

    public static function authConfigNotPersisted(): self
    {
        return new self(
            'Cannot resolve the credential of an AuthConfig that has not been persisted yet.',
        );
    }

    public static function transportFailure(string $reason): self
    {
        return new self(sprintf('HTTP transport failure: %s', $reason));
    }

    public static function insecureCredentialTransport(string $endpoint): self
    {
        return new self(sprintf(
            'Refusing to send credentials over clear-text HTTP to "%s". HTTPS is required.',
            $endpoint,
        ));
    }

    public static function malformedOAuth2Configuration(): self
    {
        return new self(
            'OAuth2 client credentials configuration is missing or malformed ' .
            '(expected a JSON object with "token_url", "client_id" and "client_secret").',
        );
    }
}
