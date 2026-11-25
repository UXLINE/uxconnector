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

namespace GlpiPlugin\Uxconnector\Domain\Sync\ValueObject;

/**
 * Stable identifier issued by the external system for one source record.
 * ObjectLink uses it with the connection and external object type to identify the GLPI target.
 * Unlike MatchField, which supports initial reconciliation, this is a source identifier,
 * not a GLPI column name.
 */
final readonly class ExternalId
{
    private const MAX_LENGTH = 191; // fits a utf8mb4 unique index on MySQL/MariaDB

    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('ExternalId must not be empty.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'ExternalId must not exceed %d characters.',
                self::MAX_LENGTH,
            ));
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
