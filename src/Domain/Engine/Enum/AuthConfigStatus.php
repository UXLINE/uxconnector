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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Enum;

/**
 * Lifecycle status of an AuthConfig aggregate.
 *
 * Cycle:
 *
 *   DRAFT --> TESTING --> ACTIVE
 *     ^          |           |
 *     |          v           v
 *     +------- ERROR <---- DISABLED
 */
enum AuthConfigStatus: string
{
    case DRAFT = 'draft';
    case TESTING = 'testing';
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
    case ERROR = 'error';

   public function label(): string {
       return match ($this) {
           self::DRAFT => 'Brouillon',
           self::TESTING => 'En cours de test',
           self::ACTIVE => 'Active',
           self::DISABLED => 'Désactivée',
           self::ERROR => 'En erreur',
       };
   }

    /**
     * Whether an AuthConfig in this status may be used by the
     * GenericHttpEngine to authenticate an outgoing request.
     */
   public function isUsable(): bool {
       return $this === self::ACTIVE;
   }
}
