<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\ObjectLinkItem;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncExecutionItem;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InternalSyncItemAccessTest extends TestCase
{
    public static function items(): iterable
    {
        foreach ([SyncJobItem::class, SyncExecutionItem::class, ObjectLinkItem::class] as $class) {
            yield $class => [$class];
        }
    }

    #[DataProvider('items')]
    public function testGenericListsSearchDirectReadsAndWritesAreClosed(string $class): void
    {
        // Even a profile allowed by CommonDBTM cannot bypass application services using core CRUD.
        \CommonDBTM::$allow = true;
        foreach (['canView', 'canCreate', 'canUpdate', 'canDelete', 'canPurge'] as $method) {
            self::assertFalse($class::$method());
        }
        $item = new $class();
        self::assertFalse($item->canViewItem());
        foreach ([READ, CREATE, UPDATE, DELETE, PURGE, READ | UPDATE] as $right) {
            self::assertFalse($item->can(28, $right));
            self::assertFalse($item->can(0, $right));
        }
    }
}
