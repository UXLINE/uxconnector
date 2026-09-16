<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Template\Entity;

final readonly class TemplateParameter
{
   public function __construct(
        public string $name,
        public string $label,
        public bool $required = true,
        public ?string $default = null,
        public ?string $description = null
    ) {
      if (trim($name) === '' || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
          throw new \InvalidArgumentException(sprintf('Invalid template parameter name "%s".', $name));
      }
      if (trim($label) === '') {
          throw new \InvalidArgumentException('Template parameter label must not be empty.');
      }
   }
}
