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
 * Weak, by-id reference from a SyncJob (Sync bounded context) to the
 * AuthConfig aggregate it authenticates through (Engine bounded
 * context).
 *
 * RULE-CONNECTORID-004 (bounded context boundary) : Sync never
 * imports Engine\ValueObject\AuthConfigId, even though it is
 * structurally identical (a positive int). Bounded contexts reference
 * each other only by id, never by sharing Value Object classes or,
 * worse, aggregates — this keeps Sync free to evolve (or even be
 * extracted) independently of Engine. Resolving this reference back
 * into a real AuthConfig is the Application layer's job (via
 * Engine's own AuthConfigRepositoryInterface), never the Domain's.
 */
final readonly class AuthConfigReference
{
   public function __construct(
        private int $value
    ) {
      if ($this->value <= 0) {
          throw new \InvalidArgumentException(
              'AuthConfig reference must be greater than zero.'
          );
      }
   }

   public function value(): int {
       return $this->value;
   }

   public function equals(self $other): bool {
       return $this->value === $other->value;
   }

   public function __toString(): string {
       return (string) $this->value;
   }
}
