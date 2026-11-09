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

namespace GlpiPlugin\Uxconnector\Domain\Engine\ValueObject;

/**
 * Relative HTTP resource used to validate an AuthConfig.
 *
 * The authentication endpoint itself is deliberately kept as a base URL.
 * TestPath lets an administrator target a real authenticated resource such
 * as "/rest/api/3/project" for Jira Cloud instead of assuming that the
 * provider's root path is a meaningful authentication probe.
 */
final readonly class TestPath
{
    private const MAX_LENGTH = 255;

    private string $value;

    public function __construct(string $value = '/')
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            $trimmed = '/';
        }

        if (!str_starts_with($trimmed, '/')) {
            throw new \InvalidArgumentException('TestPath must start with "/".');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'TestPath must not exceed %d characters.',
                self::MAX_LENGTH,
            ));
        }

        if (preg_match('/[\\r\\n\\0]/', $trimmed) === 1) {
            throw new \InvalidArgumentException('TestPath must not contain control characters.');
        }

        // A test path is relative to Endpoint. An absolute/protocol-relative
        // URL would allow bypassing the endpoint/destination validation model.
        if (str_starts_with($trimmed, '//') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $trimmed) === 1) {
            throw new \InvalidArgumentException('TestPath must be relative to the configured endpoint.');
        }

        $this->value = $trimmed;
    }

    public function value(): string
    {
        return $this->value;
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
