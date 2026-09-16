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

namespace GlpiPlugin\Uxconnector\Domain\Sync\ValueObject;

/**
 * The stable, external-system-issued identifier of a single record
 * (e.g. a remote record ID) — the actual value
 * `ObjectLink` uses to remember which GLPI item a given external
 * record was already reconciled to.
 *
 * CORRECTIF (réconciliation, revue de code externe) : distinct de
 * `MatchField`, qui ne sert plus qu'à la toute première tentative de
 * rapprochement (voir `ObjectLink`'s doc-block). `ExternalId` est la
 * valeur brute lue dans la réponse du système tiers, jamais une
 * colonne GLPI.
 */
final readonly class ExternalId
{
   private const MAX_LENGTH = 191; // fits a utf8mb4 unique index on MySQL/MariaDB

   private string $value;

   public function __construct(string $value) {
       $trimmed = trim($value);

      if ($trimmed === '') {
          throw new \InvalidArgumentException('ExternalId must not be empty.');
      }

      if (mb_strlen($trimmed) > self::MAX_LENGTH) {
          throw new \InvalidArgumentException(sprintf(
              'ExternalId must not exceed %d characters.',
              self::MAX_LENGTH
          ));
      }

       $this->value = $trimmed;
   }

   public function value(): string {
       return $this->value;
   }

   public function equals(self $other): bool {
       return $this->value === $other->value;
   }

   public function __toString(): string {
       return $this->value;
   }
}
