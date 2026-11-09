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
 * Strong identity for an AuthConfig aggregate.
 *
 * RULE-CONNECTORID-001 to 004 (adapted to the Engine bounded context) :
 * an identifier is never a raw int in the domain, is always strictly
 * positive, and is never interchangeable with the identifier of another
 * bounded context (e.g. a Sync\ValueObject\SyncJobId).
 */
final readonly class AuthConfigId
{
    public function __construct(
        private int $value
    ) {
        if ($this->value <= 0) {
            throw new \InvalidArgumentException(
                'AuthConfig identifier must be greater than zero.',
            );
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
