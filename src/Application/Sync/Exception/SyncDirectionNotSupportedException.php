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
 * Raised when a SyncJob's direction is not one ExecuteSyncJobService
 * can actually run (see SyncDirection::isImplemented()).
 *
 * CORRECTIF P0 (revue de code externe) : avant ce correctif, aucune
 * vérification n'existait - un SyncJob OUTBOUND/BIDIRECTIONAL était
 * exécuté exactement comme un SyncJob INBOUND. Cette exception est
 * levée à deux endroits (défense en profondeur) : à la création
 * (CreateSyncJobService) et, à nouveau, à chaque exécution
 * (ExecuteSyncJobService) - un SyncJob créé avant ce correctif ne
 * peut donc plus s'exécuter silencieusement de travers.
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
