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
 * Raised when CreateSyncJobService is asked to create a SyncJob
 * pointing at an AuthConfigReference that does not resolve to any
 * existing AuthConfig.
 *
 * This is the referential-integrity check promised in
 * sql/install/install-2.0.0.php's migration comment: since
 * `auth_config_reference` deliberately carries no SQL foreign key
 * (Sync and Engine are separate bounded contexts - see
 * AuthConfigReference's doc-block), the check happens here instead,
 * at creation time, in the Application layer.
 *
 * Distinct from SyncJobAuthConfigUnavailableException (raised by
 * ExecuteSyncJobService): this one prevents an invalid SyncJob from
 * ever being created; that one handles a reference that was valid at
 * creation time but no longer is by the time the job runs (the
 * AuthConfig was deleted or disabled since).
 */
final class SyncJobAuthConfigNotFoundException extends \InvalidArgumentException
{
    public static function forReference(AuthConfigReference $reference): self
    {
        return new self(sprintf(
            'AuthConfig #%d does not exist; a SyncJob cannot be created against it.',
            $reference->value(),
        ));
    }
}
