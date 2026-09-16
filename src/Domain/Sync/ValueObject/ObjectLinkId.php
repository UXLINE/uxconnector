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

final readonly class ObjectLinkId
{
   private int $value;

   public function __construct(int $value) {
      if ($value <= 0) {
          throw new \InvalidArgumentException('ObjectLinkId must be a positive integer.');
      }

       $this->value = $value;
   }

   public function value(): int {
       return $this->value;
   }

   public function equals(self $other): bool {
       return $this->value === $other->value;
   }
}
