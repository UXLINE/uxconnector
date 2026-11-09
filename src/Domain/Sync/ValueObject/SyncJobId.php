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
 * Strong identity for a SyncJob aggregate.
 *
 * RULE-CONNECTORID-001 to 004 (adapted) : never a raw int in the
 * Domain, always strictly positive, never interchangeable with the
 * identifier of another bounded context or another Sync entity
 * (e.g. SyncExecutionId).
 */
final readonly class SyncJobId
{
    public function __construct(
        private int $value
    ) {
        if ($this->value <= 0) {
            throw new \InvalidArgumentException(
                'SyncJob identifier must be greater than zero.',
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
