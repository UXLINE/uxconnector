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
 * The resource a SyncJob fetches from (INBOUND/BIDIRECTIONAL) or
 * writes to (OUTBOUND/BIDIRECTIONAL) on the external system, relative
 * to its AuthConfig's Endpoint — e.g. "/computers", "/users/active".
 *
 * Kept as its own Value Object (rather than a plain string on SyncJob)
 * so its validation rules (must start with "/", bounded length) live
 * in one place and are enforced identically whether the SyncJob is
 * being created, reconstituted from persistence, or edited.
 */
final readonly class ResourcePath
{
   private const MAX_LENGTH = 255;

   private string $value;

   public function __construct(string $value) {
       $trimmed = trim($value);

      if ($trimmed === '') {
          throw new \InvalidArgumentException('ResourcePath must not be empty.');
      }

      if (!str_starts_with($trimmed, '/')) {
          throw new \InvalidArgumentException('ResourcePath must start with "/".');
      }

      if (mb_strlen($trimmed) > self::MAX_LENGTH) {
          throw new \InvalidArgumentException(sprintf(
              'ResourcePath must not exceed %d characters.',
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
