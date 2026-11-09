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
 * Names which FieldMapping::targetField() a SyncJob uses to find an
 * already-existing GLPI record before deciding between an update and
 * a creation (e.g. "serial" for a Computer inventory sync).
 *
 * Kept as its own Value Object - rather than a bare string parameter
 * threaded through the write path - so SyncJob can enforce the
 * invariant that a MatchField always names a field that is actually
 * produced by the job's own FieldMapping list (see
 * SyncJob::assertMatchFieldIsMapped()).
 */
final readonly class MatchField
{
    private const MAX_LENGTH = 128;

    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('MatchField must not be empty.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'MatchField must not exceed %d characters.',
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
