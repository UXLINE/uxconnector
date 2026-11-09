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

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final class SyncJobNotFoundException extends \RuntimeException
{
    public static function forId(SyncJobId $id): self
    {
        return new self(sprintf('SyncJob #%d does not exist.', $id->value()));
    }
}
