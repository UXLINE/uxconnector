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
 * Names which field of the *raw* item returned by the external system
 * (before any FieldMapping is applied) holds that item's stable
 * external identifier - e.g. "id" or "sys_id" in the JSON payload.
 *
 * Deliberately independent from FieldMapping's sourceField list:
 * unlike MatchField (which must correspond to a configured
 * FieldMapping's targetField, since it is used as a GLPI column
 * name), ExternalIdField is read straight from the untransformed
 * external payload and never has to be written into GLPI at all -
 * an administrator MAY additionally choose to also map it into a
 * GLPI field (e.g. "otherserial") via a regular FieldMapping, but
 * that is an independent decision.
 */
final readonly class ExternalIdField
{
    private const MAX_LENGTH = 128;

    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('ExternalIdField must not be empty.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'ExternalIdField must not exceed %d characters.',
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
