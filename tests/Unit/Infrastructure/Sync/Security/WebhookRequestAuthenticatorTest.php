<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\Sync\Security;

use GlpiPlugin\Uxconnector\Infrastructure\Sync\Security\WebhookRequestAuthenticator;
use PHPUnit\Framework\TestCase;

final class WebhookRequestAuthenticatorTest extends TestCase
{
    private const KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testHistoricalHeaderKeyRemainsAcceptedInCompatibilityMode(): void
    {
        $authenticator = new WebhookRequestAuthenticator();
        self::assertSame(self::KEY, $authenticator->authenticate([
            'HTTP_X_UXCONNECTOR_WEBHOOK_KEY' => self::KEY,
        ], [], '{}', 1_700_000_000));
    }

    public function testQueryStringKeyIsRejectedByDefault(): void
    {
        $this->expectException(\RuntimeException::class);
        (new WebhookRequestAuthenticator())->authenticate([], ['key' => self::KEY], '{}', 1_700_000_000);
    }

    public function testLegacyQueryStringKeyCanBeTemporarilyReenabled(): void
    {
        $authenticator = new WebhookRequestAuthenticator(allowLegacyQueryKey: true);
        self::assertSame(self::KEY, $authenticator->authenticate([], ['key' => self::KEY], '{}', 1_700_000_000));
    }

    public function testHmacModeAcceptsValidFreshSignature(): void
    {
        $timestamp = '1700000000';
        $body = '{"hello":"world"}';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, self::KEY);
        $authenticator = new WebhookRequestAuthenticator(requireHmac: true);

        self::assertSame(self::KEY, $authenticator->authenticate([
            'HTTP_X_UXCONNECTOR_WEBHOOK_KEY' => self::KEY,
            'HTTP_X_UXCONNECTOR_TIMESTAMP' => $timestamp,
            'HTTP_X_UXCONNECTOR_SIGNATURE' => 'sha256=' . $signature,
        ], [], $body, 1_700_000_000));
    }

    public function testHmacModeRejectsTamperedBody(): void
    {
        $timestamp = '1700000000';
        $signature = hash_hmac('sha256', $timestamp . '.{"hello":"world"}', self::KEY);
        $authenticator = new WebhookRequestAuthenticator(requireHmac: true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid webhook signature');

        $authenticator->authenticate([
            'HTTP_X_UXCONNECTOR_WEBHOOK_KEY' => self::KEY,
            'HTTP_X_UXCONNECTOR_TIMESTAMP' => $timestamp,
            'HTTP_X_UXCONNECTOR_SIGNATURE' => $signature,
        ], [], '{"hello":"tampered"}', 1_700_000_000);
    }

    public function testHmacModeRejectsStaleTimestamp(): void
    {
        $body = '{}';
        $timestamp = '1699999000';
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, self::KEY);
        $authenticator = new WebhookRequestAuthenticator(requireHmac: true, maxClockSkewSeconds: 300);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('freshness window');

        $authenticator->authenticate([
            'HTTP_X_UXCONNECTOR_WEBHOOK_KEY' => self::KEY,
            'HTTP_X_UXCONNECTOR_TIMESTAMP' => $timestamp,
            'HTTP_X_UXCONNECTOR_SIGNATURE' => $signature,
        ], [], $body, 1_700_000_000);
    }
}
