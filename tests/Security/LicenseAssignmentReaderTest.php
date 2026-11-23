<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\LicenseAssignmentReader;
use PHPUnit\Framework\TestCase;

/** Uses GLPI's real query builder with an isolated SQLite fixture, never a GLPI database. */
final class LicenseAssignmentReaderTest extends TestCase
{
    private array $session;

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? [];
        if (!getenv('GLPI_TEST_SOURCE') || !function_exists('Safe\\preg_split')) {
            self::markTestSkipped('Set GLPI_TEST_SOURCE and SECURITY_TEST_AUTOLOAD (thecodingmachine/safe).');
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
    }

    private function reader(): LicenseAssignmentReader
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE glpi_softwarelicenses (id INTEGER PRIMARY KEY, entities_id INTEGER)');
        $fields = ['product', 'beneficiary', 'registration_number', 'job_function', 'assignment_status',
            'activation_source', 'deactivation_source', 'annual_price_source', 'monthly_price_source',
            'source_authconfig_id', 'source_location_id', 'entity_code', 'code_da', 'code_da_accounting',
            'code_ets', 'code_db', 'code_na', 'code_nb'];
        $pdo->exec('CREATE TABLE glpi_plugin_uxconnector_licenseassignments (id INTEGER PRIMARY KEY, '
            . 'softwarelicenses_id INTEGER, ' . implode(' TEXT, ', $fields) . ' TEXT)');
        $pdo->exec('INSERT INTO glpi_softwarelicenses VALUES (1, 0), (2, 10), (3, 11)');
        $insert = $pdo->prepare('INSERT INTO glpi_plugin_uxconnector_licenseassignments (id, softwarelicenses_id, beneficiary) VALUES (?, ?, ?)');
        foreach ([[1, 1, 'root'], [2, 2, 'entity10'], [3, 999, 'orphan']] as $row) {
            $insert->execute($row);
        }
        for ($id = 4; $id < 110; ++$id) {
            $insert->execute([$id, 3, 'entity11']);
        }
        $db = $this->getMockBuilder(\DBmysql::class)->disableOriginalConstructor()->onlyMethods(['request', 'tableExists'])->getMock();
        $db->method('tableExists')->willReturn(true);
        $db->method('request')->willReturnCallback(static function (array $criteria) use ($db, $pdo): array {
            $query = new \DBmysqlIterator($db);
            $query->buildQuery($criteria);
            return $pdo->query($query->getSql())->fetchAll(\PDO::FETCH_ASSOC);
        });
        return new LicenseAssignmentReader($db);
    }

    public function testEntityFilterPrecedesLimitAndExcludesOrphans(): void
    {
        $_SESSION['glpiactiveentities'] = [10];
        self::assertSame(['entity10'], array_column($this->reader()->recent(100), 'beneficiary'));
    }

    public function testRootZeroAndStringSessionIdsAreRetained(): void
    {
        $_SESSION['glpiactiveentities'] = ['0', '10'];
        self::assertSame(['entity10', 'root'], array_column($this->reader()->recent(100), 'beneficiary'));
    }

    public function testLimitIsBoundedWithinAccessibleEntity(): void
    {
        $_SESSION['glpiactiveentities'] = [11];
        self::assertCount(100, $this->reader()->recent(999));
        self::assertCount(1, $this->reader()->recent(0));
    }

    public function testMissingOrInvalidSessionNeverFallsBackToRoot(): void
    {
        foreach ([null, [], false, [false, null, '', -1, 'invalid']] as $entities) {
            $_SESSION['glpiactiveentities'] = $entities;
            self::assertSame([], $this->reader()->recent(100));
        }
    }
}
