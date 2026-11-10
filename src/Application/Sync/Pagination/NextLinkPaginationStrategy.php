<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Pagination;

use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;

final class NextLinkPaginationStrategy implements PaginationStrategyInterface
{
    public function __construct(
        private readonly PaginationConfig $config,
        private readonly JsonPathValueReader $reader
    ) {
    }

    public function nextPageReference(string $currentPath, array $response, int $pageItemCount): ?string
    {
        $value = $this->reader->read($response, (string) $this->config->option('next_link_response_path'));
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \RuntimeException('Next-link pagination value must be a string URL/path.');
        }
        return $value;
    }
}
