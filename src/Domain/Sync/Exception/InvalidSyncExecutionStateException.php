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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Exception;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;

final class InvalidSyncExecutionStateException extends \DomainException
{
    public static function alreadyFinished(SyncExecutionStatus $current): self
    {
        return new self(sprintf(
            'Cannot transition a SyncExecution that already finished with status "%s".',
            $current->value,
        ));
    }

    public static function cannotAssignIdTwice(): self
    {
        return new self('SyncExecution identifier has already been assigned.');
    }

    public static function negativeItemCount(string $label): self
    {
        return new self(sprintf('SyncExecution %s count must not be negative.', $label));
    }
}
