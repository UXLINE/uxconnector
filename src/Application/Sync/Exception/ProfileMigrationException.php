<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Exception;

/** Machine-readable failure with no source value or database statement. */
final class ProfileMigrationException extends \RuntimeException
{
    public function __construct(string $reason)
    {
        if (preg_match('/^[A-Z0-9_]{3,70}$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('Invalid migration diagnostic code.');
        }
        parent::__construct($reason);
    }
}
