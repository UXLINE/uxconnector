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

final class InvalidEntityAssignmentStrategyException extends \InvalidArgumentException
{
   public static function fixedEntityIdMustBePositive(): self {
       return new self('A FIXED entity assignment strategy requires a positive entities_id.');
   }

   public static function mappedSourceFieldMustNotBeEmpty(): self {
       return new self('A MAPPED entity assignment strategy requires a non-empty source field.');
   }

   public static function mappedTableMustNotBeEmpty(): self {
       return new self('A MAPPED entity assignment strategy requires at least one source-value-to-entity mapping.');
   }

   public static function defaultEntityIdRequiredForUseDefaultPolicy(): self {
       return new self(
           'UnknownEntityPolicy::USE_DEFAULT requires a default entities_id to fall back to.'
       );
   }
}
