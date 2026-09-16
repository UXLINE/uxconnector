<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Pagination;

use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;

final class CursorPaginationStrategy implements PaginationStrategyInterface
{
    public function __construct(
        private readonly PaginationConfig $config,
        private readonly JsonPathValueReader $reader,
        private readonly QueryParameterUpdater $queryUpdater
    ) {
    }

    public function nextPageReference(string $currentPath, array $response, int $pageItemCount): ?string
    {
        $lastPath = $this->config->option('last_page_response_path');
        if (is_string($lastPath) && $lastPath !== '' && $this->reader->read($response, $lastPath) === true) {
            return null;
        }

        $tokenPath = (string) $this->config->option('cursor_response_path');
        $token = $this->reader->read($response, $tokenPath);

        if ($token === null || $token === '') {
            if (is_string($lastPath) && $lastPath !== '' && $this->reader->read($response, $lastPath) === false) {
                throw new \RuntimeException('Cursor pagination indicates more data but no cursor token was returned.');
            }
            return null;
        }

        if (!is_scalar($token)) {
            throw new \RuntimeException('Cursor pagination token must be a scalar value.');
        }

        return $this->queryUpdater->with(
            $currentPath,
            (string) $this->config->option('cursor_request_parameter'),
            (string) $token,
            is_string($this->config->option('initial_parameter_to_remove_on_resume'))
                ? (string) $this->config->option('initial_parameter_to_remove_on_resume')
                : null
        );
    }
}
