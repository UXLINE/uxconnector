<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;

/**
 * Converts a pagination reference into a ResourcePath accepted by the generic
 * HTTP engine. Absolute links are accepted only when they remain under the
 * configured AuthConfig endpoint, preventing credential leakage to another
 * origin or outside an endpoint base path.
 */
final class PageReferenceNormalizer
{
   public function normalize(AuthConfig $authConfig, string $reference): string {
       $reference = trim($reference);
      if ($reference === '') {
          throw new \RuntimeException('Pagination returned an empty page reference.');
      }
      if (str_starts_with($reference, '/')) {
          return $reference;
      }

       $ref = parse_url($reference);
      if ($ref === false || !isset($ref['scheme'], $ref['host'])) {
          throw new \RuntimeException(sprintf('Invalid pagination page reference "%s".', $reference));
      }

       $endpoint = parse_url($authConfig->endpoint()->value());
      if ($endpoint === false || !isset($endpoint['scheme'], $endpoint['host'])) {
          throw new \RuntimeException('Configured endpoint could not be parsed.');
      }

      if (strtolower((string) $ref['scheme']) !== strtolower((string) $endpoint['scheme'])
           || strtolower((string) $ref['host']) !== strtolower((string) $endpoint['host'])
           || (int) ($ref['port'] ?? $this->defaultPort((string) $ref['scheme']))
               !== (int) ($endpoint['port'] ?? $this->defaultPort((string) $endpoint['scheme']))
       ) {
          throw new \RuntimeException('Pagination attempted to leave the configured authentication endpoint origin.');
      }

       $endpointBase = rtrim((string) ($endpoint['path'] ?? ''), '/');
       $refPath = (string) ($ref['path'] ?? '/');
      if ($endpointBase !== '' && $endpointBase !== '/') {
         if ($refPath !== $endpointBase && !str_starts_with($refPath, $endpointBase . '/')) {
             throw new \RuntimeException('Pagination attempted to leave the configured endpoint base path.');
         }
          $refPath = substr($refPath, strlen($endpointBase));
         if ($refPath === '') {
             $refPath = '/';
         }
      }

      if (!str_starts_with($refPath, '/')) {
          $refPath = '/' . $refPath;
      }
      if (isset($ref['query']) && $ref['query'] !== '') {
          $refPath .= '?' . $ref['query'];
      }

       return $refPath;
   }

   private function defaultPort(string $scheme): int {
       return strtolower($scheme) === 'https' ? 443 : 80;
   }
}
