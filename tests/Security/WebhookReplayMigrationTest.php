<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use PHPUnit\Framework\TestCase;

/** Exercises the real GLPI Migration SQL builder with a recording database adapter. */
final class WebhookReplayMigrationTest extends TestCase
{
    private array $queries = [];
    private bool $hasId = false;
    private bool $uniqueFingerprint = false;

    private function migration(bool $failUnique = false): \Migration
    {
        $db = $this->getMockBuilder(\DBmysql::class)->disableOriginalConstructor()->onlyMethods(['tableExists', 'fieldExists', 'doQuery'])->getMock();
        $db->method('tableExists')->willReturn(true);
        $db->method('fieldExists')->willReturnCallback(fn ($table, $field) => $field === 'id' ? $this->hasId : true);
        $db->method('doQuery')->willReturnCallback(function (string $sql) use ($failUnique): bool {
            $this->queries[] = $sql;
            if (str_contains($sql, 'ADD UNIQUE')) {
                if ($failUnique) {
                    throw new \RuntimeException('Test: unique index creation failed.');
                }
                $this->uniqueFingerprint = true;
            }
            if (str_contains($sql, 'ADD `id`')) {
                self::assertTrue($this->uniqueFingerprint, 'Fingerprint uniqueness must exist before switching the primary key.');
                $this->hasId = true;
            }
            return true;
        });
        $GLOBALS['DB'] = $db;
        $migration = $this->getMockBuilder(\Migration::class)->setConstructorArgs(['2.18.39'])->onlyMethods(['hasKey', 'getDefaultCollation', 'log'])->getMock();
        $migration->method('hasKey')->willReturnCallback(fn ($table, $key): bool => $key === 'PRIMARY' || ($key === 'fingerprint' && $this->uniqueFingerprint));
        $migration->method('getDefaultCollation')->willReturn('utf8mb4_unicode_ci');
        return $migration;
    }

    private function upgrade(\Migration $migration): void
    {
        $upgrade = require dirname(__DIR__, 2) . '/sql/install/migrate-webhook-request-id.php';
        $upgrade($migration);
    }

    public function testLegacyMigrationEstablishesUniqueKeyBeforeAtomicPrimaryKeyChange(): void
    {
        $migration = $this->migration();
        $this->upgrade($migration);
        self::assertCount(2, $this->queries);
        self::assertStringContainsString('ADD UNIQUE `fingerprint` (`fingerprint`)', $this->queries[0]);
        self::assertStringContainsString('DROP INDEX `PRIMARY`', $this->queries[1]);
        self::assertStringContainsString('ADD `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $this->queries[1]);
        self::assertStringNotContainsString('DELETE', implode(' ', $this->queries));
        self::assertStringNotContainsString('TRUNCATE', implode(' ', $this->queries));
        $this->upgrade($migration);
        self::assertCount(2, $this->queries, 'Same-version reinstallation must be idempotent.');
    }

    public function testFreshSchemaNeedsNoAlter(): void
    {
        $this->hasId = true;
        $this->uniqueFingerprint = true;
        $this->upgrade($this->migration());
        self::assertSame([], $this->queries);
    }

    public function testResumesAfterUniqueIndexCreation(): void
    {
        $this->uniqueFingerprint = true;
        $this->upgrade($this->migration());
        self::assertCount(1, $this->queries);
        self::assertStringContainsString('ADD `id`', $this->queries[0]);
    }

    public function testFailureStopsBeforePrimaryKeyChange(): void
    {
        try {
            $this->upgrade($this->migration(true));
            self::fail('Migration failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Test: unique index creation failed.', $error->getMessage());
            self::assertFalse($this->hasId);
            self::assertCount(1, $this->queries);
            self::assertStringNotContainsString('DROP', $this->queries[0]);
        }
    }
}
