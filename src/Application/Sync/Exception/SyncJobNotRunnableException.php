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

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;

final class SyncJobNotRunnableException extends \RuntimeException
{
    public static function forStatus(SyncJobStatus $status): self
    {
        return new self(sprintf(
            'SyncJob cannot be executed from status "%s"; it must be "%s".',
            $status->value,
            SyncJobStatus::ACTIVE->value,
        ));
    }
}
