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

/** Requires a fresh, single-use HMAC signature bound to a public job ID and raw body. */
final class WebhookRequestAuthenticator
{
    public const DEFAULT_MAX_CLOCK_SKEW_SECONDS = 300;

    /**
     * @param \Closure(int): ?array{token: string, signing_secret: string} $loadCredentials
     * @param \Closure(string, int, int): bool $claimRequest Durable, atomic replay reservation.
     */
    public function __construct(
        private readonly \Closure $loadCredentials,
        private readonly \Closure $claimRequest,
        private readonly int $maxClockSkewSeconds = self::DEFAULT_MAX_CLOCK_SKEW_SECONDS
    ) {
        if ($this->maxClockSkewSeconds < 1) {
            throw new \InvalidArgumentException('Webhook clock skew must be greater than zero.');
        }
    }

    /**
     * @param \Closure(int): ?array{token: string, signing_secret: string} $loadCredentials
     * @param \Closure(string, int, int): bool $claimRequest
     */
    public static function fromEnvironment(\Closure $loadCredentials, \Closure $claimRequest): self
    {
        return new self(
            $loadCredentials,
            $claimRequest,
            self::environmentClockSkew(),
        );
    }

    public static function requiresHmac(): bool
    {
        // Compatibility accessor for the UI. A server variable cannot disable signatures anymore.
        return true;
    }

    /** @param array<string, mixed> $server */
    public function authenticate(
        #[\SensitiveParameter] array $server,
        #[\SensitiveParameter] string $rawBody,
        ?int $now = null
    ): string {
        $key = strtolower(trim((string) ($server['HTTP_X_UXCONNECTOR_WEBHOOK_KEY'] ?? '')));
        $publicId = trim((string) ($server['HTTP_X_UXCONNECTOR_WEBHOOK_ID'] ?? ''));
        $timestamp = trim((string) ($server['HTTP_X_UXCONNECTOR_TIMESTAMP'] ?? ''));
        $signature = trim((string) ($server['HTTP_X_UXCONNECTOR_SIGNATURE'] ?? ''));
        if ($key !== '') {
            throw new \RuntimeException('Signed webhook requests must not contain a bearer key.');
        }
        return $this->authenticateSignature($publicId, $timestamp, $signature, $rawBody, $now ?? time());
    }

    private function authenticateSignature(
        string $publicId,
        string $timestamp,
        #[\SensitiveParameter] string $signature,
        #[\SensitiveParameter] string $rawBody,
        int $now
    ): string {
        $jobId = filter_var($publicId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($jobId === false || (string) $jobId !== $publicId || preg_match('/^[0-9]{10}$/D', $timestamp) !== 1) {
            throw new \RuntimeException('Invalid signed webhook identifier or timestamp.');
        }
        if (abs($now - (int) $timestamp) > $this->maxClockSkewSeconds) {
            throw new \RuntimeException('Webhook timestamp is outside the accepted freshness window.');
        }

        $signature = strtolower($signature);
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            throw new \RuntimeException('Missing or invalid webhook signature.');
        }

        $credentials = ($this->loadCredentials)($jobId);
        if ($credentials === null) {
            throw new \RuntimeException('Unknown webhook interaction.');
        }
        foreach (['token', 'signing_secret'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', $credentials[$field] ?? '') !== 1) {
                throw new \RuntimeException('Webhook credential unavailable.');
            }
        }
        $message = $publicId . '.' . $timestamp . '.' . $rawBody;
        if (!hash_equals(hash_hmac('sha256', $message, $credentials['signing_secret']), $signature)) {
            throw new \RuntimeException('Invalid webhook signature.');
        }

        $fingerprint = hash('sha256', $publicId . '.' . $timestamp . '.' . $signature);
        if (!(($this->claimRequest)($fingerprint, (int) $timestamp + $this->maxClockSkewSeconds, $now))) {
            throw new WebhookReplayException('Webhook request already received.');
        }

        return $credentials['token'];
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
