<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\ValueObject;

/**
 * Path of the record collection inside a decoded JSON response.
 *
 * `$` designates the response root itself. Any other value is interpreted
 * by the generic JsonPathValueReader (for example `records`, `data.items`
 * or `$['result-list']`). The value object deliberately carries no vendor
 * knowledge: external-system specifics are template concerns.
 */
final readonly class CollectionPath
{
   private const MAX_LENGTH = 255;

   private string $value;

   public function __construct(string $value) {
       $trimmed = trim($value);
      if ($trimmed === '') {
          throw new \InvalidArgumentException('CollectionPath must not be empty; use "$" for the response root.');
      }
      if (mb_strlen($trimmed) > self::MAX_LENGTH) {
          throw new \InvalidArgumentException(sprintf('CollectionPath must not exceed %d characters.', self::MAX_LENGTH));
      }
       $this->value = $trimmed;
   }

   public static function root(): self {
       return new self('$');
   }

   public function value(): string {
       return $this->value;
   }

   public function isRoot(): bool {
       return $this->value === '$';
   }
}
