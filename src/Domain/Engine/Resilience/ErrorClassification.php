<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Engine\Resilience;

/** Pure classification rules shared by all HTTP adapters. */
final class ErrorClassification
{
    public function isRetryableStatus(int $status): bool
    {
        return in_array($status, [408, 429, 500, 502, 503, 504], true);
    }

    public function requiresAuthenticationRenewal(int $status): bool
    {
        return $status === 401;
    }

    public function isHttp2StreamFailure(string $message): bool
    {
        $lower = strtolower($message);
        return str_contains($lower, 'http/2')
            || str_contains($lower, 'http2')
            || str_contains($lower, 'stream error');
    }
}
