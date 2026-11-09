<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Pagination;

use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\PaginationType;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;

final class PaginationStrategyResolver
{
    public function __construct(private readonly JsonPathValueReader $reader) {}

    public function resolve(PaginationConfig $config): PaginationStrategyInterface
    {
        return match ($config->type()) {
            PaginationType::NONE => new NoPaginationStrategy(),
            PaginationType::CURSOR => new CursorPaginationStrategy($config, $this->reader, new QueryParameterUpdater()),
            PaginationType::OFFSET => new OffsetPaginationStrategy($config, $this->reader, new QueryParameterUpdater()),
            PaginationType::COUNT_OFFSET => new CountOffsetPaginationStrategy($config, new QueryParameterUpdater()),
            PaginationType::RANGE => new RangePaginationStrategy($config, $this->reader, new QueryParameterUpdater()),
            PaginationType::NEXT_LINK => new NextLinkPaginationStrategy($config, $this->reader),
        };
    }
}
