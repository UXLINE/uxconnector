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
 * What EntityAssignmentStrategy::resolve() should do when its MAPPED
 * strategy cannot find the source value in its mapping table (a
 * "Nantes"/"Paris"/"Lyon" table encountering an unexpected "Lille",
 * say).
 */
enum UnknownEntityPolicy: string
{
    case FAIL = 'fail';
    case USE_DEFAULT = 'use_default';

   public function label(): string {
       return match ($this) {
           self::FAIL => 'Échouer',
           self::USE_DEFAULT => 'Utiliser une entité par défaut',
       };
   }
}
