<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Shared\Persistence;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;

/**
 * Thin adapter delegating transaction demarcation to GLPI's own
 * DBmysql connection, so Application Services never call
 * beginTransaction()/commit()/rollback() directly (RULE-036/060).
 *
 * Requires a real GLPI runtime (the global $DB instance) - validated
 * in tests/Integration, not in tests/Unit.
 */
final class GlpiTransactionManager implements TransactionManagerInterface
{
    public function __construct(
        private readonly DBmysql $db
    ) {}

    public function begin(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        $this->db->rollback();
    }
}
