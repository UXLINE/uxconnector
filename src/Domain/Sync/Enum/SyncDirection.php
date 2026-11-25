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
     * Whether the runtime supports this direction. Used for form choices, creation and execution.
     * Bidirectional synchronization uses two independently controlled interactions;
     * the ambiguous single-job BIDIRECTIONAL mode is not implemented.
     */
    public function isImplemented(): bool
    {
        return $this !== self::BIDIRECTIONAL;
    }
}
