<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Pagination;

use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;

/** Pagination for APIs using an inclusive "range=start-end" query parameter. */
final class RangePaginationStrategy implements PaginationStrategyInterface
{
    public function __construct(
        private readonly PaginationConfig $config,
        private readonly JsonPathValueReader $reader,
        private readonly QueryParameterUpdater $queryUpdater
    ) {
    }

    public function nextPageReference(string $currentPath, array $response, int $pageItemCount): ?string
    {
        $pageSize = (int) $this->config->option('page_size');
        if ($pageSize < 1) {
            throw new \RuntimeException('Range pagination page size must be greater than zero.');
        }
        $totalPath = (string) $this->config->option('total_response_path');
        $total = $this->reader->read($response, $totalPath);
        if (!is_numeric($total)) {
            throw new \RuntimeException(sprintf(
                'Range pagination total at "%s" is missing or non-numeric.',
                $totalPath
            ));
        }
        $name = (string) $this->config->option('range_request_parameter');
        $query = parse_url($currentPath, PHP_URL_QUERY);
        $start = 0;
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $raw = (string) ($params[$name] ?? '0-' . ($pageSize - 1));
            if (preg_match('/^(\d+)-(\d+)$/', $raw, $m) === 1) {
                $start = (int) $m[1];
            }
        }
        $nextStart = $start + max(0, $pageItemCount);
        if ($pageItemCount === 0 || $nextStart >= (int) $total) {
            return null;
        }
        return $this->queryUpdater->with($currentPath, $name, $nextStart . '-' . ($nextStart + $pageSize - 1));
    }
}
