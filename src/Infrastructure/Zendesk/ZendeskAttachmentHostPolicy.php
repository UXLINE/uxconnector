<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Zendesk;

use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/** Restricts comment-supplied URLs and every redirect to the tenant or Zendesk's attachment CDN. */
final class ZendeskAttachmentHostPolicy
{
    /** No wildcard tenant access, arbitrary port, URL credentials or protocol downgrade is accepted. */
    public static function allows(string $url, Endpoint $tenant): bool
    {
        $parts = parse_url($url);
        $origin = parse_url($tenant->value());
        if (
            !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)
        ) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = (int) ($parts['port'] ?? 443);
        if (
            ($origin['scheme'] ?? '') === 'https' && $host === strtolower(rtrim($origin['host'], '.'))
            && $port === (int) ($origin['port'] ?? 443)
        ) {
            return true;
        }
        return $port === 443 && ($host === 'zdusercontent.com' || str_ends_with($host, '.zdusercontent.com'));
    }
}
