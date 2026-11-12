<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication;

use GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * Exchanges a client_id/client_secret pair for a bearer access token,
 * via the OAuth2 Client Credentials grant (RFC 6749 §4.4) - the only
 * OAuth2 flow this plugin implements, being the only one compatible
 * with an unattended, scheduled execution (see AuthMethod::isImplemented()'s
 * doc-block for why Authorization Code is deliberately out of scope).
 *
 * Deliberately a small, standalone HTTP client rather than a reuse of
 * GenericHttpEngine: this class calls an arbitrary token endpoint
 * (administrator-configured, distinct from any AuthConfig's own
 * `endpoint`), not an AuthConfig's API - routing it back through
 * GenericHttpEngine would be circular (GenericHttpEngine is what
 * *builds* an OAuth2ClientCredentialsAuthenticationStrategy in the
 * first place, via AuthenticationStrategyFactory). The duplication of
 * a bounded-read/no-redirect stream setup with GenericHttpEngine is a
 * conscious trade-off: this class's needs are simpler (a single POST,
 * no configurable HttpRequest, no AuthConfig), and touching
 * GenericHttpEngine's already-hardened, thoroughly tested transport
 * code was judged riskier than a small amount of duplication.
 *
 * Client-credentials tokens are cached in-process until shortly before
 * expiry. This avoids one token exchange per paginated API request while
 * deliberately keeping tokens out of persistent storage.
 */
final class OAuth2TokenClient
{
    private const TIMEOUT_SECONDS = 30;

    /**
     * A token response is tiny (a JSON object with a handful of
     * fields) - bounded far below GenericHttpEngine's general-purpose
     * 10 MiB limit specifically so a misbehaving token endpoint can
     * never be used to exhaust memory via this code path either.
     */
    private const MAX_RESPONSE_BYTES = 1 * 1024 * 1024; // 1 MiB
    private const READ_CHUNK_BYTES = 8192;
    private const DEFAULT_CACHE_SECONDS = 300;
    private const EXPIRY_SKEW_SECONDS = 30;

    /** @var array<string, array{token:string,expires_at:int}> */
    private array $clientCredentialsCache = [];

    public function __construct(
        private readonly DestinationPolicyInterface $destinationPolicy
    ) {
    }

    /**
     * @throws HttpEngineException if the token endpoint is an
     *         SSRF-forbidden destination, is unreachable, returns a
     *         non-2xx status, or its response does not contain an
     *         access_token.
     */
    public function invalidateClientCredentialsToken(
        string $tokenUrl,
        string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        ?string $scope
    ): void {
        $cacheKey = hash('sha256', implode("\0", [$tokenUrl, $clientId, $clientSecret, (string) $scope]));
        unset($this->clientCredentialsCache[$cacheKey]);
    }

    public function fetchClientCredentialsToken(
        string $tokenUrl,
        string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        ?string $scope
    ): string {
        $cacheKey = hash('sha256', implode("\0", [$tokenUrl, $clientId, $clientSecret, (string) $scope]));
        $cached = $this->clientCredentialsCache[$cacheKey] ?? null;

        if ($cached !== null && $cached['expires_at'] > time()) {
            return $cached['token'];
        }

        // Zendesk, Microsoft identity platform and RFC 6749-compatible
        // providers accept the client credentials in the request body.
        // This is also compatible with providers that reject HTTP Basic
        // client authentication for this grant.
        $result = $this->fetchFormTokenPayload(
            $tokenUrl,
            [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => $scope,
            ],
            [],
        );

        $ttl = isset($result['expires_in']) && is_numeric($result['expires_in'])
            ? max(1, (int) $result['expires_in'])
            : self::DEFAULT_CACHE_SECONDS;
        $usableTtl = max(1, $ttl - min(self::EXPIRY_SKEW_SECONDS, max(0, $ttl - 1)));
        $this->clientCredentialsCache[$cacheKey] = [
            'token' => $result['access_token'],
            'expires_at' => time() + $usableTtl,
        ];

        return $result['access_token'];
    }

    /**
    * @param array<string, mixed> $fields
    * @param list<string>         $extraHeaders
    */
    private function fetchFormToken(
        string $tokenUrl,
        #[\SensitiveParameter] array $fields,
        array $extraHeaders
    ): string {
        return $this->fetchFormTokenPayload($tokenUrl, $fields, $extraHeaders)['access_token'];
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $extraHeaders
     *
     * @return array{access_token:string,expires_in?:int|float|string}
     */
    private function fetchFormTokenPayload(
        string $tokenUrl,
        #[\SensitiveParameter] array $fields,
        array $extraHeaders
    ): array {
        $tokenEndpoint = new Endpoint($tokenUrl);
        if (!$tokenEndpoint->isSecure()) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::insecureCredentialTransport(
                $tokenEndpoint->value()
            );
        }
        $this->destinationPolicy->assertAllowed($tokenEndpoint);

        $body = http_build_query(array_filter(
            $fields,
            static fn(mixed $value): bool => $value !== null && $value !== '',
        ));
        $headers = ['Content-Type: application/x-www-form-urlencoded', ...$extraHeaders];
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $deadline = hrtime(true) + self::TIMEOUT_SECONDS * 1_000_000_000;
        $stream = @fopen($tokenUrl, 'rb', false, $context);
        if ($stream === false) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::transportFailure(
                'Unable to contact the OAuth2 token endpoint.',
            );
        }
        try {
            $responseBody = $this->readWithSizeLimit($stream, $deadline);
            $meta = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        /** @var list<string> $headerLines */
        $headerLines = $meta['wrapper_data'] ?? [];
        $statusCode = $this->parseStatusCode($headerLines);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::transportFailure(
                sprintf('OAuth2 token endpoint returned HTTP %d.', $statusCode)
            );
        }
        $decoded = json_decode($responseBody, true);
        if (
            !is_array($decoded) || !is_string($decoded['access_token'] ?? null)
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $decoded['access_token']) !== 1
        ) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::transportFailure(
                'OAuth2 token endpoint response did not contain a usable "access_token".'
            );
        }
        // Do not retain refresh tokens or unrelated response fields in the caller/cache.
        return array_intersect_key($decoded, ['access_token' => true, 'expires_in' => true]);
    }

    /**
     * Exchanges a GLPI OAuth2 client plus a real GLPI user credential for
     * an access token using the Password grant. GLPI 11 requires a user-bound
     * grant for the normal `api` scope; Client Credentials is reserved for
     * inventory-only access.
     */
    public function fetchPasswordToken(
        string $tokenUrl,
        string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        string $username,
        #[\SensitiveParameter] string $password,
        ?string $scope
    ): string {
        return $this->fetchFormToken($tokenUrl, [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'username' => $username,
            'password' => $password,
            'scope' => $scope,
        ], []);
    }

    /**
     * @param resource $stream
     */
    private function readWithSizeLimit($stream, float $deadline): string
    {
        $body = '';
        $bytesRead = 0;

        while (!feof($stream)) {
            $remaining = (int) (($deadline - hrtime(true)) / 1000);
            if ($remaining <= 0) {
                throw HttpEngineException::transportFailure('OAuth2 token response exceeded the time limit.');
            }
            stream_set_timeout($stream, intdiv($remaining, 1_000_000), $remaining % 1_000_000);
            $chunk = fread($stream, min(self::READ_CHUNK_BYTES, self::MAX_RESPONSE_BYTES + 1 - $bytesRead));
            if (hrtime(true) >= $deadline || (stream_get_meta_data($stream)['timed_out'] ?? false)) {
                throw HttpEngineException::transportFailure('OAuth2 token response exceeded the time limit.');
            }
            if ($chunk === false || ($chunk === '' && !feof($stream))) {
                throw HttpEngineException::transportFailure('OAuth2 token response could not be read.');
            }

            $bytesRead += strlen($chunk);

            if ($bytesRead > self::MAX_RESPONSE_BYTES) {
                throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::transportFailure(sprintf(
                    'OAuth2 token endpoint response exceeded the %d byte limit.',
                    self::MAX_RESPONSE_BYTES,
                ));
            }

            $body .= $chunk;
        }

        return $body;
    }

    /** Debug output must not dump cached access tokens. */
    public function __debugInfo(): array
    {
        return ['cached_token_count' => count($this->clientCredentialsCache)];
    }

    /**
     * @param list<string> $lines
     */
    private function parseStatusCode(array $lines): int
    {
        // follow_location is disabled, so the response never contains
        // more than one hop - the status line is always first.
        if (!isset($lines[0]) || preg_match('#^HTTP/\S+\s+(\d{3})#', $lines[0], $matches) !== 1) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::transportFailure(
                'Malformed or missing HTTP status line from the OAuth2 token endpoint.',
            );
        }

        return (int) $matches[1];
    }
}
