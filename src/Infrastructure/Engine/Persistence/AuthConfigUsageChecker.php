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

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigUsageCheckerInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

/**
 * Infrastructure adapter checking whether the Sync bounded context still
 * references an AuthConfig. There is intentionally no SQL foreign key between
 * the two bounded contexts, so deletion integrity is enforced here.
 */
final class AuthConfigUsageChecker implements AuthConfigUsageCheckerInterface
{
   private const SYNC_JOB_TABLE = 'glpi_plugin_uxconnector_syncjobs';

   public function __construct(
        private readonly DBmysql $db
    ) {
   }

   public function isUsed(AuthConfigId $id): bool {
      if (!$this->db->tableExists(self::SYNC_JOB_TABLE)) {
          return false;
      }

       $iterator = $this->db->request([
           'SELECT' => ['id'],
           'FROM'   => self::SYNC_JOB_TABLE,
           'WHERE'  => ['auth_config_reference' => $id->value()],
           'LIMIT'  => 1,
       ]);

      foreach ($iterator as $_row) {
          return true;
      }

       return false;
   }
}
