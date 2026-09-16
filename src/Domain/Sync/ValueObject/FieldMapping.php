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
 * Maps one field of the external system's payload to one field of a
 * GLPI itemtype (or the reverse, depending on the owning SyncJob's
 * SyncDirection).
 *
 * RULE-VALUEOBJECT-001/002 : immutable, self-validating. A
 * FieldMapping carries no knowledge of *which* GLPI itemtype it
 * targets — that context lives on the owning SyncJob — so the same
 * Value Object shape works for a Computer sync, a User sync, or any
 * future target without modification.
 */
final readonly class FieldMapping
{
   private const MAX_FIELD_LENGTH = 128;

   private string $sourceField;
   private string $targetField;

   public function __construct(
        string $sourceField,
        string $targetField,
        private bool $required = false,
        private ?string $transform = null
    ) {
       $this->sourceField = $this->validateField($sourceField, 'source');
       $this->targetField = $this->validateField($targetField, 'target');
   }

   public function sourceField(): string {
       return $this->sourceField;
   }

   public function targetField(): string {
       return $this->targetField;
   }

   public function isRequired(): bool {
       return $this->required;
   }

    /**
     * Name of a transformation to apply to the value before writing
     * it (e.g. "trim", "uppercase", "date_iso8601"). Null means the
     * raw value is used as-is. The Domain does not interpret this
     * string itself — a dedicated Infrastructure\Sync\Transform
     * registry will, once field transformation is implemented.
     */
   public function transform(): ?string {
       return $this->transform;
   }

   public function equals(self $other): bool {
       return $this->sourceField === $other->sourceField
           && $this->targetField === $other->targetField
           && $this->required === $other->required
           && $this->transform === $other->transform;
   }

   private function validateField(string $field, string $label): string {
       $trimmed = trim($field);

      if ($trimmed === '') {
          throw new \InvalidArgumentException(
              sprintf('FieldMapping %s field must not be empty.', $label)
          );
      }

      if (mb_strlen($trimmed) > self::MAX_FIELD_LENGTH) {
          throw new \InvalidArgumentException(
              sprintf(
                  'FieldMapping %s field must not exceed %d characters.',
                  $label,
                  self::MAX_FIELD_LENGTH
              )
          );
      }

       return $trimmed;
   }
}
