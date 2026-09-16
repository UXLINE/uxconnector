<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Security;

final class CopilotApiKeyAuthenticator
{
   private const TABLE = 'glpi_plugin_uxconnector_copilotclients';

    /** @return array<string,mixed>|null */
   public function authenticate(string $plainKey): ?array {
       global $DB;
      if ($plainKey === '' || strlen($plainKey) < 32 || !$DB->tableExists(self::TABLE)) {
          return null;
      }
       $hash = hash('sha256', $plainKey);
       $iterator = $DB->request([
           'FROM' => self::TABLE,
           'WHERE' => ['api_key_hash' => $hash, 'is_active' => 1],
           'LIMIT' => 1,
       ]);
      foreach ($iterator as $row) {
          return $row;
      }
       return null;
   }
}
