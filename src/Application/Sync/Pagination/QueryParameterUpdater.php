<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Pagination;

final class QueryParameterUpdater
{
    public function with(string $path, string $name, string $value, ?string $removeParameter = null): string
    {
        $fragment = '';
        if (str_contains($path, '#')) {
            [$path, $fragmentPart] = explode('#', $path, 2);
            $fragment = '#' . $fragmentPart;
        }

        $base = $path;
        $query = '';
        if (str_contains($path, '?')) {
            [$base, $query] = explode('?', $path, 2);
        }

        parse_str($query, $parameters);
        if ($removeParameter !== null && $removeParameter !== '') {
            unset($parameters[$removeParameter]);
        }
        $parameters[$name] = $value;
        $queryString = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return $base . ($queryString !== '' ? '?' . $queryString : '') . $fragment;
    }
}
