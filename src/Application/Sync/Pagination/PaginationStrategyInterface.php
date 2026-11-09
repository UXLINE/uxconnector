<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Pagination;

interface PaginationStrategyInterface
{
    /** @param array<mixed> $response */
    public function nextPageReference(string $currentPath, array $response, int $pageItemCount): ?string;
}
