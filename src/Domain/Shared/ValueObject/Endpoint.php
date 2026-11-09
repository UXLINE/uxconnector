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

namespace GlpiPlugin\Uxconnector\Domain\Shared\ValueObject;

/**
 * Represents a validated, normalized HTTP(S) endpoint.
 *
 * Shared across bounded contexts (Engine, Sync) since both need to
 * reach an external API. RULE-VALUEOBJECT-001 to 005 apply: immutable,
 * self-validating, no GLPI/SQL dependency, no mutable state.
 */
final readonly class Endpoint
{
    private const MAX_LENGTH = 255;

    private string $value;

    public function __construct(string $value)
    {
        $trimmed = rtrim(trim($value), '/');

        if ($trimmed === '') {
            throw new \InvalidArgumentException(
                'Endpoint must not be empty.',
            );
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Endpoint must not exceed %d characters.',
                    self::MAX_LENGTH,
                ),
            );
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a valid URL.', $trimmed),
            );
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);

        if (!in_array($scheme, ['https', 'http'], true)) {
            throw new \InvalidArgumentException(
                'Endpoint scheme must be "https" or "http".',
            );
        }

        if (parse_url($trimmed, PHP_URL_USER) !== null || parse_url($trimmed, PHP_URL_PASS) !== null) {
            throw new \InvalidArgumentException(
                'Endpoint credentials must be configured through an authentication method, never embedded in the URL.',
            );
        }

        if (parse_url($trimmed, PHP_URL_QUERY) !== null || parse_url($trimmed, PHP_URL_FRAGMENT) !== null) {
            throw new \InvalidArgumentException(
                'Endpoint must be a base URL without query string or fragment; put resource-specific parameters in the resource path.',
            );
        }

        $this->value = $trimmed;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isSecure(): bool
    {
        return parse_url($this->value, PHP_URL_SCHEME) === 'https';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
