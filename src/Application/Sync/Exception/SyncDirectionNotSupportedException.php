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

namespace GlpiPlugin\Uxconnector\Application\Sync\Exception;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;

/**
 * Rejects directions unsupported by the runtime at creation and again at execution.
 * Persisted jobs cannot silently execute in a different direction after an upgrade.
 */
final class SyncDirectionNotSupportedException extends \InvalidArgumentException
{
    public static function forDirection(SyncDirection $direction): self
    {
        return new self(sprintf(
            'Synchronization direction "%s" is not yet supported by this plugin.',
            $direction->value,
        ));
    }
}
