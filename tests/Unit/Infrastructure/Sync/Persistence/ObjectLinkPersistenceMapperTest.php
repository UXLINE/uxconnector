<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\Sync\Persistence;

use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\ObjectLinkPersistenceMapper;
use PHPUnit\Framework\TestCase;

final class ObjectLinkPersistenceMapperTest extends TestCase
{
   public function testLegacyRecordCanBeRehydratedWithRequestedExternalType(): void {
       $mapper = new ObjectLinkPersistenceMapper();
       $link = $mapper->toDomain([
           'id' => 9,
           'plugin_uxconnector_syncjobs_id' => 3,
           'auth_config_reference' => 4,
           'external_id' => '10042',
           'external_object_type' => 'record',
           'itemtype' => 'ticket',
           'item_id' => 88,
           'last_seen_at' => '2026-08-18 22:00:00',
       ], 'issue');

       self::assertSame('issue', $link->externalObjectType());
       self::assertSame('issue', $mapper->toPersistence($link)['external_object_type']);
   }
}
