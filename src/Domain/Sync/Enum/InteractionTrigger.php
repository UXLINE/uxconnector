<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Enum;

/** How an interaction is initiated. */
enum InteractionTrigger: string
{
    case POLLING = 'polling';
    case WEBHOOK = 'webhook';
    case GLPI_EVENT = 'glpi_event';

   public function label(): string {
       return match ($this) {
           self::POLLING => 'Planifiée / manuelle',
           self::WEBHOOK => 'Événement externe (webhook)',
           self::GLPI_EVENT => 'Événement GLPI',
       };
   }

   public function isScheduled(): bool {
       return $this === self::POLLING;
   }
}
