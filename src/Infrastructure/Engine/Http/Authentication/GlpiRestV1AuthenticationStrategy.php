<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication;

use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Resilience\ErrorClassification;
use GlpiPlugin\Uxconnector\Domain\Engine\Resilience\RetryPolicy;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/** GLPI 9/10 REST v1 session authentication. Session-Token is memory-only. */
final class GlpiRestV1AuthenticationStrategy implements RenewableSessionAuthenticationStrategy
{
    private const MAX_RESPONSE_BYTES = 1024 * 1024;

    /** @var array<string,string> */
    private static array $sessions = [];
    private readonly array $config;
    private readonly string $cacheKey;

    public function __construct(
        private readonly Endpoint $endpoint,
        #[\SensitiveParameter] string $secret,
        private readonly DestinationPolicyInterface $destinationPolicy,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        private readonly ErrorClassification $errorClassification = new ErrorClassification()
    ) {
        $config = json_decode($secret, true);
        if (
            !is_array($config) || !is_string($config['app_token'] ?? null) || !is_string($config['user_token'] ?? null)
            || trim($config['app_token']) === '' || trim($config['user_token']) === ''
        ) {
            throw new \RuntimeException('Malformed GLPI REST v1 credential configuration.');
        }
        $this->config = $config;
        $this->cacheKey = hash('sha256', $endpoint->value() . '|' . $config['app_token'] . '|' . $config['user_token']);
    }

    public function authenticate(HttpRequest $request): HttpRequest
    {
        $token = $this->sessionToken();
        return $request
            ->withHeader('App-Token', (string) $this->config['app_token'])
            ->withHeader('Session-Token', $token);
    }

    public function responseRequiresRenewal(HttpResponse $response): bool
    {
        if (($this->config['renew_session'] ?? true) !== true) {
            return false;
        }
        if ($response->statusCode === 401) {
            return true;
        }
        return str_contains($response->body, 'ERROR_SESSION_TOKEN_INVALID');
    }

    public function invalidateSession(): void
    {
        unset(self::$sessions[$this->cacheKey]);
    }

    public function closeSession(): void
    {
        if (($this->config['close_session'] ?? true) !== true || !isset(self::$sessions[$this->cacheKey])) {
            return;
        }
        $token = self::$sessions[$this->cacheKey];
        try {
            $this->rawRequest((string) ($this->config['close_path'] ?? '/killSession'), [
                'App-Token' => (string) $this->config['app_token'],
                'Session-Token' => $token,
            ]);
        } finally {
            unset(self::$sessions[$this->cacheKey]);
        }
    }

    private function sessionToken(): string
    {
        if (isset(self::$sessions[$this->cacheKey])) {
            return self::$sessions[$this->cacheKey];
        }
        $response = $this->rawRequest((string) ($this->config['init_path'] ?? '/initSession'), [
            'App-Token' => (string) $this->config['app_token'],
            'Authorization' => 'user_token ' . (string) $this->config['user_token'],
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(sprintf('GLPI REST v1 initSession failed with HTTP %d.', $response['status']));
        }
        $decoded = json_decode($response['body'], true);
        $field = (string) ($this->config['session_field'] ?? 'session_token');
        $token = is_array($decoded) && is_string($decoded[$field] ?? null) ? trim($decoded[$field]) : '';
        if ($token === '') {
            throw new \RuntimeException('GLPI REST v1 initSession did not return a session token.');
        }
        self::$sessions[$this->cacheKey] = $token;
        return $token;
    }

    /** @return array{status:int,body:string} */
    private function rawRequest(string $path, array $headers): array
    {
        $this->destinationPolicy->assertAllowed($this->endpoint);
        $url = rtrim($this->endpoint->value(), '/') . '/' . ltrim($path, '/');
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $maxAttempts = min($this->retryPolicy->maxAttempts, max(1, ((int) ($this->config['retry_count'] ?? 4)) + 1));
        $lastError = 'unknown transport error';
        $timeout = max(1, min(120, (int) ($this->config['timeout'] ?? 60)));

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET', 'header' => implode("\r\n", $headerLines),
                    'timeout' => $timeout,
                    'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                    'protocol_version' => 1.1,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $deadline = hrtime(true) + $timeout * 1_000_000_000;
            $stream = @fopen($url, 'rb', false, $context);
            if ($stream === false) {
                $lastError = (string) (error_get_last()['message'] ?? 'unknown stream error');
                if ($attempt < $maxAttempts) {
                    $this->sleepBackoff($attempt);
                    continue;
                }
                break;
            }
            try {
                $body = $this->readSessionResponse($stream, $deadline);
                $meta = stream_get_meta_data($stream);
            } finally {
                fclose($stream);
            }
            $status = 0;
            $responseHeaders = [];
            foreach (($meta['wrapper_data'] ?? []) as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m) === 1) {
                    $status = (int) $m[1];
                    continue;
                }
                $parts = explode(':', (string) $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
            }
            if (!$this->errorClassification->isRetryableStatus($status) || $attempt >= $maxAttempts) {
                return ['status' => $status, 'body' => $body];
            }
            if ($status === 429 && isset($responseHeaders['retry-after'])) {
                $raw = $responseHeaders['retry-after'];
                $seconds = ctype_digit($raw) ? (int) $raw : max(1, (int) ((strtotime($raw) ?: time() + 1) - time()));
                sleep($this->retryPolicy->clampRetryAfter($seconds));
            } else {
                $this->sleepBackoff($attempt);
            }
        }

        throw new \RuntimeException(
            'GLPI REST v1 transport failure after retry budget: ' . $this->safeTransportMessage($lastError)
        );
    }

    /** @param resource $stream */
    private function readSessionResponse($stream, float $deadline): string
    {
        $body = '';
        while (!feof($stream)) {
            $remaining = (int) (($deadline - hrtime(true)) / 1000);
            if ($remaining <= 0) {
                throw new \RuntimeException('GLPI REST v1 session response exceeded the time limit.');
            }
            stream_set_timeout($stream, intdiv($remaining, 1_000_000), $remaining % 1_000_000);
            $chunk = fread($stream, min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
            if (hrtime(true) >= $deadline || (stream_get_meta_data($stream)['timed_out'] ?? false)) {
                throw new \RuntimeException('GLPI REST v1 session response exceeded the time limit.');
            }
            if ($chunk === false || ($chunk === '' && !feof($stream))) {
                throw new \RuntimeException('GLPI REST v1 session response could not be read.');
            }
            $body .= $chunk;
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new \RuntimeException('GLPI REST v1 session response exceeded the 1 MiB safety limit.');
            }
        }
        return $body;
    }

    private function sleepBackoff(int $attempt): void
    {
        $jitter = random_int(0, $this->retryPolicy->maxJitterMs);
        usleep($this->retryPolicy->backoffDelayMs($attempt, $jitter) * 1000);
    }

    private function safeTransportMessage(string $message): string
    {
        return preg_replace(
            '/(token|authorization|password|secret)[^\s]*/i',
            '[redacted]',
            $message
        ) ?: 'transport error';
    }
}
