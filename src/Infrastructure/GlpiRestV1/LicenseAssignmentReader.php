<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Sync\ReadModel\LicenseAssignmentReaderInterface;

/** Read-only fixed-shape query; no untrusted table, filter or sort inputs. */
final class LicenseAssignmentReader implements LicenseAssignmentReaderInterface
{
    public function __construct(private readonly DBmysql $db)
    {
    }

    public function recent(int $limit): array
    {
        if (!$this->db->tableExists(LicenseAssignmentItem::getTable())) {
            return [];
        }
        $rows = $this->db->request([
            'SELECT' => [
                'id', 'product', 'beneficiary', 'registration_number', 'job_function', 'assignment_status',
                'activation_source', 'deactivation_source', 'annual_price_source', 'monthly_price_source',
                'softwarelicenses_id', 'source_authconfig_id', 'source_location_id',
                'entity_code', 'code_da', 'code_da_accounting', 'code_ets', 'code_db', 'code_na', 'code_nb',
            ],
            'FROM' => LicenseAssignmentItem::getTable(),
            'ORDER' => 'id DESC',
            'LIMIT' => min(max(1, $limit), 100),
        ]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = $row;
        }
        return $result;
    }
}
