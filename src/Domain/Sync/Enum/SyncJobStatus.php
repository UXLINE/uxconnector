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
 * Lifecycle status of a SyncJob configuration.
 *
 * Cycle:
 *
 *   DRAFT --> ACTIVE <--> PAUSED
 *               |
 *               v
 *             ERROR
 */
enum SyncJobStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case ERROR = 'error';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Brouillon',
            self::ACTIVE => 'Active',
            self::PAUSED => 'En pause',
            self::ERROR => 'En erreur',
        };
    }

    /**
     * Whether a SyncJob in this status may be picked up by
     * SyncJobCronTask for execution.
     */
    public function isRunnable(): bool
    {
        return $this === self::ACTIVE;
    }
}
