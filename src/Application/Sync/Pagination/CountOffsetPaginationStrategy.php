<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Pagination;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;

/**
 * Offset pagination for list APIs that return a bare JSON array and expose no
 * total/offset metadata in the response.
 *
 * The strategy advances the configured offset by the number of records that
 * were actually returned, and stops as soon as a page is shorter than the
 * configured page size. This matches the GLPI 11 High-Level API list endpoints
 * (for example /Assets/Computer?start=0&limit=100), where the payload itself is
 * the collection.
 */
final class CountOffsetPaginationStrategy implements PaginationStrategyInterface
{
    public function __construct(
        private readonly PaginationConfig $config,
        private readonly QueryParameterUpdater $queryUpdater
    ) {
    }

    public function nextPageReference(string $currentPath, array $response, int $pageItemCount): ?string
    {
        $pageSize = (int) $this->config->option('page_size');
        if ($pageSize < 1) {
            throw new \RuntimeException('Count-offset pagination page size must be greater than zero.');
        }

        if ($pageItemCount === 0 || $pageItemCount < $pageSize) {
            return null;
        }

        $offsetParameter = (string) $this->config->option('offset_request_parameter');
        $currentOffset = $this->queryParameterAsInt($currentPath, $offsetParameter);
        $nextOffset = $currentOffset + $pageItemCount;

        return $this->queryUpdater->with($currentPath, $offsetParameter, (string) $nextOffset);
    }

    private function queryParameterAsInt(string $path, string $name): int
    {
        $query = parse_url($path, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return 0;
        }

        parse_str($query, $parameters);
        $value = $parameters[$name] ?? 0;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
