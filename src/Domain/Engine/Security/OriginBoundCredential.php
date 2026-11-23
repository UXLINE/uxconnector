<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Engine\Security;

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/** Binds encrypted credentials to the endpoint and method that can use them. */
final class OriginBoundCredential
{
    private const PREFIX = "UXC-CREDENTIAL-ORIGIN-1\n";

    public static function wrap(Endpoint $endpoint, AuthMethod $method, #[\SensitiveParameter] string $secret): string
    {
        return self::binding($endpoint, $method) . $secret;
    }

    public static function unwrap(Endpoint $endpoint, AuthMethod $method, #[\SensitiveParameter] string $stored): string
    {
        if (!self::isBound($stored)) {
            throw new \RuntimeException('The credential has no origin binding. Complete the plugin upgrade or replace it.');
        }
        $binding = self::binding($endpoint, $method);
        if (!str_starts_with($stored, $binding)) {
            throw new \RuntimeException('The credential does not match the current connection origin or method.');
        }

        return substr($stored, strlen($binding));
    }

    public static function isBound(#[\SensitiveParameter] string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    private static function binding(Endpoint $endpoint, AuthMethod $method): string
    {
        return self::PREFIX . $endpoint->origin() . "\n" . $method->value . "\n";
    }
}
