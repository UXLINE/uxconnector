<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Engine\Resilience;

/** Connector-agnostic retry budget and exponential-backoff policy. */
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 5,
        public int $baseDelayMs = 250,
        public int $maxDelayMs = 8000,
        public int $maxJitterMs = 250,
        public int $maxRetryAfterSeconds = 120
    ) {
        if ($maxAttempts < 1 || $baseDelayMs < 0 || $maxDelayMs < $baseDelayMs || $maxJitterMs < 0) {
            throw new \InvalidArgumentException('Invalid retry policy.');
        }
    }

    public function backoffDelayMs(int $attempt, int $jitterMs = 0): int
    {
        $attempt = max(1, $attempt);
        $jitterMs = min($this->maxJitterMs, max(0, $jitterMs));
        return min($this->maxDelayMs, $this->baseDelayMs * (2 ** max(0, $attempt - 1))) + $jitterMs;
    }

    public function clampRetryAfter(int $seconds): int
    {
        return min($this->maxRetryAfterSeconds, max(1, $seconds));
    }
}
