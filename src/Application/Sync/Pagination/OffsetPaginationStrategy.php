<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Pagination;

use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;

final class OffsetPaginationStrategy implements PaginationStrategyInterface
{
   public function __construct(
        private readonly PaginationConfig $config,
        private readonly JsonPathValueReader $reader,
        private readonly QueryParameterUpdater $queryUpdater
    ) {
   }

   public function nextPageReference(string $currentPath, array $response, int $pageItemCount): ?string {
       $lastPath = $this->config->option('last_page_response_path');
      if (is_string($lastPath) && $lastPath !== '' && $this->reader->read($response, $lastPath) === true) {
          return null;
      }

       $offset = $this->readNumeric($response, (string) $this->config->option('offset_response_path'), 'offset');
       $pageSize = max(1, $this->readNumeric($response, (string) $this->config->option('page_size_response_path'), 'page size'));
       $total = max(0, $this->readNumeric($response, (string) $this->config->option('total_response_path'), 'total'));
       $advance = $pageItemCount > 0 ? $pageItemCount : $pageSize;
       $next = $offset + $advance;

      if ($next >= $total) {
          return null;
      }

       return $this->queryUpdater->with(
           $currentPath,
           (string) $this->config->option('offset_request_parameter'),
           (string) $next
       );
   }

    /** @param array<mixed> $response */
   private function readNumeric(array $response, string $path, string $label): int {
       $value = $this->reader->read($response, $path);
      if (!is_numeric($value)) {
          throw new \RuntimeException(sprintf('Offset pagination %s at "%s" is missing or non-numeric.', $label, $path));
      }
       return (int) $value;
   }
}
