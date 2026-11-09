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

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;

/**
 * Raised when a SyncJob's AuthConfigReference cannot be resolved to a
 * usable AuthConfig - the cross-bounded-context lookup this Service
 * performs (see AuthConfigReference's class doc-block).
 */
final class SyncJobAuthConfigUnavailableException extends \RuntimeException
{
    public static function referenceNotFound(AuthConfigReference $reference): self
    {
        return new self(sprintf(
            'AuthConfig #%d referenced by this SyncJob does not exist (it may have been deleted).',
            $reference->value(),
        ));
    }

    public static function notUsable(AuthConfigReference $reference): self
    {
        return new self(sprintf(
            'AuthConfig #%d referenced by this SyncJob is not currently active.',
            $reference->value(),
        ));
    }
}
