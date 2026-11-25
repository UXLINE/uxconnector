<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use GlpiPlugin\Uxconnector\Infrastructure\Sync\Migration\LegacyJobScopeReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyJobScopeReportTest extends TestCase
{
    public static function scopes(): iterable
    {
        yield 'null' => [null, 'unconfigured', [], true];
        yield 'blank' => ['', 'unconfigured', [], true];
        yield 'corrupt JSON' => ['{', 'invalid', [], true];
        yield 'root is valid' => ['{"type":"fixed","entity_id":0}', 'fixed', [0], false];
        yield 'fixed' => ['{"type":"fixed","entity_id":10}', 'fixed', [10], false];
        yield 'negative' => ['{"type":"fixed","entity_id":-1}', 'invalid', [], true];
        yield 'missing' => ['{"type":"fixed"}', 'invalid', [], true];
        yield 'mapped same scope' => ['{"type":"mapped","source_field":"entity","mapping":{"a":10,"b":10}}', 'mapped', [10], false];
        yield 'mapped multiple scopes' => ['{"type":"mapped","source_field":"entity","mapping":{"a":10,"b":0}}', 'mapped', [0,10], true];
        yield 'mapped default included' => ['{"type":"mapped","source_field":"entity","mapping":{"a":10},"unknown_policy":"use_default","default_entity_id":11}', 'mapped', [10,11], true];
        yield 'default missing' => ['{"type":"mapped","source_field":"entity","mapping":{"a":10},"unknown_policy":"use_default"}', 'invalid', [], true];
        yield 'source missing' => ['{"type":"mapped","mapping":{"a":10}}', 'invalid', [], true];
        yield 'boolean ID' => ['{"type":"fixed","entity_id":false}', 'invalid', [], true];
    }

    #[DataProvider('scopes')]
    public function testScopeClassification(?string $json, string $type, array $ids, bool $review): void
    {
        self::assertSame(['strategy' => $type, 'entity_ids' => $ids, 'scope_review_required' => $review], LegacyJobScopeReport::scope($json));
    }

    public function testInventoryIsReadOnlyAndOnlySelectsActiveJobsWithoutPayloads(): void
    {
        $db = $this->getMockBuilder(\DBmysql::class)->disableOriginalConstructor()->onlyMethods(['request', 'doQuery', 'update', 'delete'])->getMock();
        foreach (['doQuery', 'update', 'delete'] as $method) {
            $db->expects(self::never())->method($method);
        }
        $db->expects(self::once())->method('request')->with([
            'SELECT' => ['id', 'auth_config_reference', 'entity_assignment_strategy'],
            'FROM' => 'glpi_plugin_uxconnector_syncjobs',
            'WHERE' => ['status' => 'active'], 'ORDER' => 'id ASC',
        ])->willReturn([['id' => 28,'auth_config_reference' => 8,'entity_assignment_strategy' => null]]);
        $report = (new LegacyJobScopeReport($db))->build();
        self::assertStringContainsString('unavailable', $report['creator_scope_assessment']);
        self::assertSame(28, $report['active_jobs'][0]['job_id']);
        self::assertTrue($report['active_jobs'][0]['scope_review_required']);
    }
}
