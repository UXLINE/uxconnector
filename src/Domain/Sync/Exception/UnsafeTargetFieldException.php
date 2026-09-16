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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Exception;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;

final class UnsafeTargetFieldException extends \DomainException
{
   public static function notWritable(TargetItemtype $itemtype, string $field): self {
       return new self(sprintf(
           'Target field "%s" is not writable for GLPI itemtype "%s". Choose a field from the UXConnector allow-list.',
           $field,
           $itemtype->value
       ));
   }

   public static function notMatchable(TargetItemtype $itemtype, string $field): self {
       return new self(sprintf(
           'Match field "%s" is not an approved reconciliation key for GLPI itemtype "%s".',
           $field,
           $itemtype->value
       ));
   }
}
