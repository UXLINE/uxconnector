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
 * Status of a single SyncExecution (one run of a SyncJob).
 *
 * Cycle: PENDING -> RUNNING -> (SUCCESS | PARTIAL | FAILED)
 */
enum SyncExecutionStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case PARTIAL = 'partial';
    case FAILED = 'failed';

   public function label(): string {
       return match ($this) {
           self::PENDING => 'En attente',
           self::RUNNING => 'En cours',
           self::SUCCESS => 'Succès',
           self::PARTIAL => 'Succès partiel',
           self::FAILED => 'Échec',
       };
   }

    /**
     * Whether this status represents a terminal state — no further
     * transition is expected once an execution reaches it.
     */
   public function isFinished(): bool {
       return in_array($this, [self::SUCCESS, self::PARTIAL, self::FAILED], true);
   }
}
