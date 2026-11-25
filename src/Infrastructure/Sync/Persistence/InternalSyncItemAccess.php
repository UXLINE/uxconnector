<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

/**
 * Internal persistence records are not a public CRUD API.
 * Core REST list/search checks canView(), whereas a direct read checks can(..., READ).
 * Plugin services use repositories and apply their own job/entity checks before access.
 */
trait InternalSyncItemAccess
{
    public static function canView(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public function canViewItem(): bool
    {
        return false;
    }

    public function can($ID, int $right, ?array &$input = null): bool
    {
        return false;
    }
}
