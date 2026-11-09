<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime;

/**
 * Process-local loop guard.
 *
 * An inbound synchronization writes through CommonDBTM, so GLPI fires the same
 * item_add/item_update hooks as a human action. Without a guard this would
 * immediately trigger an outbound interaction in the same request. The guard
 * deliberately has process scope only; cross-request idempotence is handled by
 * ObjectLink and remains extensible with hashes/checkpoints later.
 */
final class SyncRuntimeGuard
{
    private static int $depth = 0;

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    public static function run(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
