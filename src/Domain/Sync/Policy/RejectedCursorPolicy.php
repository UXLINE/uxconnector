<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Policy;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/** A rejected Search Export cursor can be discarded once, retaining the query. */
final class RejectedCursorPolicy
{
    public static function restartPath(SyncJob $job, string $path, int $status): ?string
    {
        if ($status !== 422 || $job->templateId() !== 'zendesk.tickets.nonclosed.inbound') {
            return null;
        }
        $parts = parse_url($path);
        if (!is_array($parts) || isset($parts['host']) || isset($parts['scheme'])
            || !in_array($parts['path'] ?? '', ['/api/v2/search/export.json', '/api/v2/search/export'], true)) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        if (!is_array($query['page'] ?? null) || !is_string($query['page']['after'] ?? null)
            || $query['page']['after'] === '') {
            return null;
        }
        unset($query['page']['after']);
        return $parts['path'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
