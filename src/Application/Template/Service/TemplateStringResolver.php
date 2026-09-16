<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Template\Service;

use GlpiPlugin\Uxconnector\Domain\Template\Entity\TemplateParameter;

/** Resolves template placeholders once, at template instantiation time. */
final class TemplateStringResolver
{
    /**
     * @param list<TemplateParameter> $definitions
     * @param array<string,mixed> $provided
     * @return array<string,string>
     */
   public function resolveParameters(array $definitions, array $provided): array {
       $resolved = [];
      foreach ($definitions as $definition) {
          $raw = array_key_exists($definition->name, $provided)
              ? (string) $provided[$definition->name]
              : ($definition->default ?? '');
          $value = trim($raw);
         if ($definition->required && $value === '') {
            throw new \InvalidArgumentException(sprintf('Template parameter "%s" is required.', $definition->label));
         }
          $resolved[$definition->name] = $value;
      }
       return $resolved;
   }

    /** @param array<string,string> $parameters */
   public function resolveString(string $template, array $parameters): string {
       $result = preg_replace_callback(
           '/\{\{\s*([a-z][a-z0-9_]*)(?<filters>(?:\|[a-z]+)*)\s*\}\}/i',
           static function (array $match) use ($parameters): string {
               $name = $match[1];
            if (!array_key_exists($name, $parameters)) {
               throw new \InvalidArgumentException(sprintf('Unknown template parameter "%s".', $name));
            }

               $value = $parameters[$name];
               $filters = array_values(array_filter(explode('|', ltrim((string) ($match['filters']), '|'))));
            foreach ($filters as $filter) {
               $value = match (strtolower($filter)) {
                   'urlencode' => rawurlencode($value),
                   'upper' => function_exists('mb_strtoupper') ? \mb_strtoupper($value) : strtoupper($value),
                   'lower' => function_exists('mb_strtolower') ? \mb_strtolower($value) : strtolower($value),
                   default => throw new \InvalidArgumentException(sprintf('Unsupported template filter "%s".', $filter)),
               };
            }

               return $value;
           },
           $template
       );

      if ($result === null) {
          throw new \InvalidArgumentException('Template placeholder resolution failed.');
      }
      if (preg_match('/\{\{.*\}\}/', $result) === 1) {
          throw new \InvalidArgumentException(sprintf('Unresolved template placeholder remains in "%s".', $result));
      }
       return $result;
   }

   /**
    * @param array<string, string> $parameters
    */
   public function resolveRecursive(mixed $value, array $parameters): mixed {
      if (is_string($value)) {
          return $this->resolveString($value, $parameters);
      }
      if (is_array($value)) {
          $resolved = [];
         foreach ($value as $key => $item) {
             $resolved[$key] = $this->resolveRecursive($item, $parameters);
         }
          return $resolved;
      }
       return $value;
   }
}
