<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

/** Resolves a generic outbound request definition against one GLPI item. */
final class OutboundRuntimeValueResolver
{
    /** @param array<string,mixed> $fields */
   public function resolveBody(mixed $definition, array $fields): mixed {
      if (is_array($definition)) {
          $resolved = [];
         foreach ($definition as $key => $value) {
            $resolved[$key] = $this->resolveBody($value, $fields);
         }
          return $resolved;
      }

      if (!is_string($definition)) {
          return $definition;
      }

      if (str_starts_with($definition, '@field_optional:')) {
          $field = trim(substr($definition, 16));
         if ($field === '') {
             throw new \RuntimeException('Outbound optional GLPI field name must not be empty.');
         }
          return $fields[$field] ?? null;
      }

      if (str_starts_with($definition, '@field:')) {
          $field = trim(substr($definition, 7));
         if ($field === '' || !array_key_exists($field, $fields)) {
             throw new \RuntimeException(sprintf('Outbound GLPI field "%s" is unavailable.', $field));
         }
          return $fields[$field];
      }

      if (str_starts_with($definition, '@literal:')) {
          $literal = substr($definition, 9);
          $decoded = json_decode($literal, true);
          return json_last_error() === JSON_ERROR_NONE ? $decoded : $literal;
      }

       return $definition;
   }

    /** @param array<string,mixed> $fields */
   public function resolvePath(string $path, array $fields, int $itemId, ?string $externalId): string {
       return (string) preg_replace_callback('/\{\{([^}]+)}}/', function (array $matches) use ($fields, $itemId, $externalId): string {
           $token = trim($matches[1]);
         if ($token === 'item_id') {
            return rawurlencode((string) $itemId);
         }
         if ($token === 'external_id') {
            if ($externalId === null || $externalId === '') {
                throw new \RuntimeException('Outbound path requires an external_id but no ObjectLink exists.');
            }
             return rawurlencode($externalId);
         }
         if (str_starts_with($token, 'field.')) {
             $field = substr($token, 6);
            if ($field === '' || !array_key_exists($field, $fields)) {
                 throw new \RuntimeException(sprintf('Outbound path GLPI field "%s" is unavailable.', $field));
            }
             return rawurlencode((string) $fields[$field]);
         }
           throw new \RuntimeException(sprintf('Unknown outbound runtime placeholder "%s".', $token));
       }, $path);
   }
}
