<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Pagination;

final class NoPaginationStrategy implements PaginationStrategyInterface
{
   public function nextPageReference(string $currentPath, array $response, int $pageItemCount): ?string {
       return null;
   }
}
