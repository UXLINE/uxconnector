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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Enum;

/**
 * Direction a SyncJob moves data in, relative to GLPI.
 */
enum SyncDirection: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';
    case BIDIRECTIONAL = 'bidirectional';

    public function label(): string
    {
        return match ($this) {
            self::INBOUND => 'Système externe → GLPI',
            self::OUTBOUND => 'GLPI → Système externe',
            self::BIDIRECTIONAL => 'Bidirectionnelle',
        };
    }

    public function readsFromExternalSystem(): bool
    {
        return $this !== self::OUTBOUND;
    }

    public function writesToExternalSystem(): bool
    {
        return $this !== self::INBOUND;
    }

    /**
     * Whether UXConnector has a runtime for this direction. Bidirectional
     * behaviour is deliberately represented by two independently controllable
     * interactions rather than one ambiguous job.
     *
     * CORRECTIF P0 (revue de code externe) : avant ce correctif,
     * OUTBOUND et BIDIRECTIONAL étaient sélectionnables dans le
     * formulaire de création, mais ExecuteSyncJobService exécutait
     * systématiquement un GET vers le système externe suivi d'une
     * écriture GLPI, quelle que soit la direction choisie -
     * c'est-à-dire qu'il se comportait comme INBOUND en silence.
     * Cette méthode est l'unique source de vérité utilisée à la fois
     * pour filtrer les choix affichés (Presentation) et pour refuser
     * la création/l'exécution côté serveur (Application).
     */
    public function isImplemented(): bool
    {
        return $this !== self::BIDIRECTIONAL;
    }
}
