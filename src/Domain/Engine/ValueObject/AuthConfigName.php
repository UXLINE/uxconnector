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

namespace GlpiPlugin\Uxconnector\Domain\Engine\ValueObject;

/**
 * Represents the human-readable name of an AuthConfig (e.g.
 * "Microsoft Graph - Production", "Zabbix - Site Nantes").
 *
 * RULE-VALUEOBJECT-001/002 : immutable, self-validating in the
 * constructor.
 */
final readonly class AuthConfigName
{
   private const MIN_LENGTH = 2;
   private const MAX_LENGTH = 128;

   private string $value;

   public function __construct(string $value) {
       $trimmed = trim($value);

      if (mb_strlen($trimmed) < self::MIN_LENGTH) {
          throw new \InvalidArgumentException(
              sprintf(
                  'AuthConfig name must contain at least %d characters.',
                  self::MIN_LENGTH
              )
          );
      }

      if (mb_strlen($trimmed) > self::MAX_LENGTH) {
          throw new \InvalidArgumentException(
              sprintf(
                  'AuthConfig name must not exceed %d characters.',
                  self::MAX_LENGTH
              )
          );
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
