<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\SessionLifecycleHttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Resilience\ErrorClassification;
use GlpiPlugin\Uxconnector\Domain\Engine\Resilience\RetryPolicy;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\AuthenticationStrategyFactory;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\RenewableSessionAuthenticationStrategy;

/**
 * Generic resilient HTTP transport shared by every connector.
 * TLS verification and destination policy are mandatory; reusable credentials
 * are never written to logs/exceptions by this class.
 */
final class GenericHttpEngine implements HttpEngineInterface, SessionLifecycleHttpEngineInterface
{
    private const TIMEOUT_SECONDS = 60;
    private const USER_AGENT = 'UXConnector/2.18.13 (+https://www.helpline.fr)';
    private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;
    private const READ_CHUNK_BYTES = 8192;

    public function __construct(
        private readonly AuthConfigSecretStoreInterface $secretStore,
        private readonly CredentialVaultInterface $vault,
        private readonly AuthenticationStrategyFactory $strategyFactory,
        private readonly DestinationPolicyInterface $destinationPolicy,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        private readonly ErrorClassification $errorClassification = new ErrorClassification()
    ) {
    }

    public function execute(AuthConfig $authConfig, HttpRequest $request): HttpResponse
    {
        $this->assertTransportAllowed($authConfig);
        $secret = $this->resolveSecret($authConfig);
        $strategy = $this->strategyFactory->create($authConfig, $secret);
        $forceHttp1 = false;
        $authRenewed = false;
        $attempt = 0;
        $lastTransportError = null;

        while ($attempt < $this->retryPolicy->maxAttempts) {
            $attempt++;
            $authenticatedRequest = $strategy->authenticate($request);
            try {
                $response = $this->send(
                    $this->buildUrl($authConfig, $authenticatedRequest),
                    $authenticatedRequest,
                    $forceHttp1,
                );
                $lastTransportError = null;
            } catch (HttpEngineException $exception) {
                $lastTransportError = $exception;
                if ($attempt >= $this->retryPolicy->maxAttempts) {
                    throw $exception;
                }
                if ($this->errorClassification->isHttp2StreamFailure($exception->getMessage())) {
                    $forceHttp1 = true;
                }
                $this->backoff($attempt);
                continue;
            }

            if (
                $strategy instanceof RenewableSessionAuthenticationStrategy
                && !$authRenewed
                && $strategy->responseRequiresRenewal($response)
            ) {
                $authRenewed = true;
                $strategy->invalidateSession();
                continue;
            }

            if (
                !$this->errorClassification->isRetryableStatus(
                    $response->statusCode
                ) || $attempt >= $this->retryPolicy->maxAttempts
            ) {
                return $response;
            }

            if ($response->statusCode === 429) {
                $seconds = $this->retryAfterSeconds($response);
                if ($seconds > 0) {
                    sleep($seconds);
                }
            } else {
                $this->backoff($attempt);
            }
        }

        if ($lastTransportError !== null) {
            throw $lastTransportError;
        }
        throw HttpEngineException::transportFailure('Retry budget exhausted.');
    }

    public function closeSession(AuthConfig $authConfig): void
    {
        if (!$authConfig->authMethod()->requiresVaultedCredentials()) {
            return;
        }
        $strategy = $this->strategyFactory->create($authConfig, $this->resolveSecret($authConfig));
        if ($strategy instanceof RenewableSessionAuthenticationStrategy) {
            $strategy->closeSession();
        }
    }

    private function assertTransportAllowed(AuthConfig $authConfig): void
    {
        if ($authConfig->authMethod()->requiresVaultedCredentials() && !$authConfig->endpoint()->isSecure()) {
            throw HttpEngineException::insecureCredentialTransport($authConfig->endpoint()->value());
        }
        $this->destinationPolicy->assertAllowed($authConfig->endpoint());
    }

    private function resolveSecret(AuthConfig $authConfig): ?string
    {
        if (!$authConfig->authMethod()->requiresVaultedCredentials()) {
            return null;
        }
        $id = $authConfig->id();
        if ($id === null) {
            throw HttpEngineException::authConfigNotPersisted();
        }
        $protectedSecret = $this->secretStore->get($id);
        if ($protectedSecret === null) {
            throw HttpEngineException::missingCredential($authConfig->authMethod());
        }
        return $this->vault->reveal($protectedSecret);
    }

    private function buildUrl(AuthConfig $authConfig, HttpRequest $request): string
    {
        $url = rtrim($authConfig->endpoint()->value(), '/') . '/' . ltrim($request->path, '/');
        if ($request->queryParameters !== []) {
            $url .= '?' . http_build_query($request->queryParameters);
        }
        return $url;
    }

    private function send(string $url, HttpRequest $request, bool $forceHttp1): HttpResponse
    {
        if (function_exists('curl_init')) {
            return $this->sendWithCurl($url, $request, $forceHttp1);
        }
        return $this->sendWithStreams($url, $request);
    }

    private function sendWithCurl(string $url, HttpRequest $request, bool $forceHttp1): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw HttpEngineException::transportFailure('Unable to initialize cURL.');
        }
        $headers = [];
        $body = '';
        $tooLarge = false;
        $requestHeaders = [];
        foreach ($request->headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }
        $requestHeaders[] = 'User-Agent: ' . self::USER_AGENT;

        $options = [
            CURLOPT_CUSTOMREQUEST => $request->method->value,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                    return strlen($line);
                }
                $parts = explode(':', $trimmed, 2);
                if (count($parts) === 2) {
                    $headers[trim($parts[0])] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if (defined('CURLOPT_HTTP_VERSION')) {
            if ($forceHttp1 && defined('CURL_HTTP_VERSION_1_1')) {
                $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
            } elseif (defined('CURL_HTTP_VERSION_2TLS')) {
                $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
            }
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($tooLarge) {
            throw HttpEngineException::transportFailure('Response body exceeded the 10485760 byte limit.');
        }
        if ($ok === false || $errno !== 0) {
            throw HttpEngineException::transportFailure($error !== '' ? $error : ('cURL error ' . $errno));
        }
        return new HttpResponse($status, $headers, $body);
    }

    private function sendWithStreams(string $url, HttpRequest $request): HttpResponse
    {
        $context = stream_context_create([
            'http' => [
                'method' => $request->method->value,
                'header' => $this->formatHeaders($request),
                'content' => $request->body ?? '',
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw HttpEngineException::transportFailure(error_get_last()['message'] ?? 'unknown stream error');
        }
        try {
            $body = $this->readWithSizeLimit($stream);
            $meta = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        $lines = $meta['wrapper_data'] ?? [];
        return new HttpResponse($this->parseStatusCode($lines), $this->parseHeaders($lines), $body);
    }

    private function retryAfterSeconds(HttpResponse $response): int
    {
        $raw = trim((string) ($response->header('Retry-After') ?? '1'));
        if (ctype_digit($raw)) {
            return $this->retryPolicy->clampRetryAfter((int) $raw);
        }
        $timestamp = strtotime($raw);
        return $timestamp === false ? 1 : $this->retryPolicy->clampRetryAfter($timestamp - time());
    }

    private function backoff(int $attempt): void
    {
        $jitterMs = random_int(0, $this->retryPolicy->maxJitterMs);
        usleep($this->retryPolicy->backoffDelayMs($attempt, $jitterMs) * 1000);
    }

    /** @param resource $stream */
    private function readWithSizeLimit($stream): string
    {
        $body = '';
        $bytes = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, self::READ_CHUNK_BYTES);
            if ($chunk === false) {
                throw HttpEngineException::transportFailure('Unable to read HTTP response stream.');
            }
            $bytes += strlen($chunk);
            if ($bytes > self::MAX_RESPONSE_BYTES) {
                throw HttpEngineException::transportFailure('Response body exceeded the 10485760 byte limit.');
            }
            $body .= $chunk;
        }
        return $body;
    }

    private function formatHeaders(HttpRequest $request): string
    {
        $lines = [];
        foreach ($request->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'User-Agent: ' . self::USER_AGENT;
        return implode("\r\n", $lines);
    }

    private function lastHopLines(array $lines): array
    {
        $last = 0;
        foreach ($lines as $i => $line) {
            if (str_starts_with((string) $line, 'HTTP/')) {
                $last = $i;
            }
        }
        return array_slice($lines, $last);
    }

    private function parseStatusCode(array $lines): int
    {
        $hop = $this->lastHopLines($lines);
        if (!isset($hop[0]) || preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $hop[0], $m) !== 1) {
            throw HttpEngineException::transportFailure('Malformed or missing HTTP status line.');
        }
        return (int) $m[1];
    }

    private function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach (array_slice($this->lastHopLines($lines), 1) as $line) {
            $parts = explode(':', (string) $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $headers;
    }
}
