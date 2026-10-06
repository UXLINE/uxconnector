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

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Security;

/**
 * Authenticates inbound webhook requests without coupling the public endpoint
 * to a specific external provider.
 *
 * Backward compatibility:
 * - the historical X-UXConnector-Webhook-Key bearer header remains accepted;
 * - legacy ?key=... support is disabled by default and can be re-enabled with
 *   UXCONNECTOR_ALLOW_LEGACY_WEBHOOK_QUERY_KEY=1 during a migration window.
 *
 * Optional hardened mode:
 * - set UXCONNECTOR_REQUIRE_WEBHOOK_HMAC=1;
 * - send X-UXConnector-Timestamp and X-UXConnector-Signature;
 * - signature = HMAC-SHA256("<timestamp>.<raw body>", webhook key).
 *
 * When signature headers are supplied, they are always verified, even when
 * hardened mode is not globally required. This lets integrations migrate to
 * signed requests without a flag day.
 */
final class WebhookRequestAuthenticator
{
    public const DEFAULT_MAX_CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        private readonly bool $requireHmac = false,
        private readonly bool $allowLegacyQueryKey = false,
        private readonly int $maxClockSkewSeconds = self::DEFAULT_MAX_CLOCK_SKEW_SECONDS
    ) {
        if ($this->maxClockSkewSeconds < 1) {
            throw new \InvalidArgumentException('Webhook clock skew must be greater than zero.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            requireHmac: getenv('UXCONNECTOR_REQUIRE_WEBHOOK_HMAC') === '1',
            allowLegacyQueryKey: getenv('UXCONNECTOR_ALLOW_LEGACY_WEBHOOK_QUERY_KEY') === '1',
            maxClockSkewSeconds: self::environmentClockSkew()
        );
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     */
    public function authenticate(array $server, array $query, string $rawBody, ?int $now = null): string
    {
        $key = strtolower(trim((string) ($server['HTTP_X_UXCONNECTOR_WEBHOOK_KEY'] ?? '')));

        if ($key === '' && $this->allowLegacyQueryKey) {
            $key = strtolower(trim((string) ($query['key'] ?? '')));
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new \RuntimeException('Unknown webhook interaction.');
        }

        $timestamp = trim((string) ($server['HTTP_X_UXCONNECTOR_TIMESTAMP'] ?? ''));
        $signature = trim((string) ($server['HTTP_X_UXCONNECTOR_SIGNATURE'] ?? ''));
        $hasSignatureMaterial = $timestamp !== '' || $signature !== '';

        if ($this->requireHmac || $hasSignatureMaterial) {
            $this->assertValidHmac($key, $timestamp, $signature, $rawBody, $now ?? time());
        }

        return $key;
    }

    private function assertValidHmac(
        string $key,
        string $timestamp,
        string $signature,
        string $rawBody,
        int $now
    ): void {
        if (preg_match('/^\d{10,}$/D', $timestamp) !== 1) {
            throw new \RuntimeException('Missing or invalid webhook timestamp.');
        }

        $timestampValue = (int) $timestamp;
        if (abs($now - $timestampValue) > $this->maxClockSkewSeconds) {
            throw new \RuntimeException('Webhook timestamp is outside the accepted freshness window.');
        }

        if (str_starts_with(strtolower($signature), 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $signature = strtolower($signature);
        if (preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            throw new \RuntimeException('Missing or invalid webhook signature.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $key);
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid webhook signature.');
        }
    }

    private static function environmentClockSkew(): int
    {
        $raw = getenv('UXCONNECTOR_WEBHOOK_MAX_CLOCK_SKEW');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_MAX_CLOCK_SKEW_SECONDS;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);

        return $value === false ? self::DEFAULT_MAX_CLOCK_SKEW_SECONDS : (int) $value;
    }
}
