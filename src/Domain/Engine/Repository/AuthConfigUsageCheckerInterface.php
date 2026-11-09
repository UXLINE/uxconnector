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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Repository;

use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

/**
 * Cross-context read port used to protect AuthConfig referential integrity.
 * The Engine application layer does not know how SyncJobs are persisted; it
 * only asks whether a configuration is still referenced before a hard purge.
 */
interface AuthConfigUsageCheckerInterface
{
    public function isUsed(AuthConfigId $id): bool;
}
