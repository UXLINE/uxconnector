<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security;

/** Opens only already-approved addresses, sharing one deadline and preserving the original TLS identity. */
final class PinnedStreamOpener
{
    private readonly \Closure $open;
    private readonly \Closure $clock;

    /**
     * @param null|\Closure(string, resource): (resource|false) $open
     * @param null|\Closure(): (int|float) $clock Monotonic nanoseconds, like hrtime(true).
     */
    public function __construct(?\Closure $open = null, ?\Closure $clock = null)
    {
        $this->open = $open ?? static fn (string $url, $context) => @fopen($url, 'rb', false, $context);
        $this->clock = $clock ?? static fn (): int|float => hrtime(true);
    }

    /**
     * A failed HTTP wrapper open may already have sent the request. Only safe methods and explicitly
     * retryable authentication exchanges may move to another address; business writes are not replayed here.
     *
     * @param array<string, mixed> $httpOptions
     * @return resource|false
     */
    public function open(
        PinnedHttpDestination $destination,
        #[\SensitiveParameter] array $httpOptions,
        float $deadline,
        bool $retryAuthenticationExchange = false
    ) {
        $urls = $destination->streamUrls();
        $method = strtoupper((string) ($httpOptions['method'] ?? 'GET'));
        if (!$retryAuthenticationExchange && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $urls = array_slice($urls, 0, 1);
        }
        foreach ($urls as $index => $url) {
            $remaining = ($deadline - ($this->clock)()) / 1_000_000_000;
            if ($remaining <= 0) {
                return false;
            }
            // Reserve time for the other approved addresses rather than exhausting the whole budget on IPv6.
            $options = array_replace($httpOptions, [
                'timeout' => $remaining / (count($urls) - $index),
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'proxy' => '',
                'request_fulluri' => false,
            ]);
            $context = stream_context_create(['http' => $options, 'ssl' => $destination->tlsOptions()]);
            $stream = ($this->open)($url, $context);
            if (is_resource($stream)) {
                if (($this->clock)() >= $deadline) {
                    fclose($stream);
                    return false;
                }
                return $stream;
            }
        }
        return false;
    }
}
