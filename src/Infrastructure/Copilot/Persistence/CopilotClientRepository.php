<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Persistence;

use GlpiPlugin\Uxconnector\Domain\Copilot\Repository\CopilotClientRepositoryInterface;

final class CopilotClientRepository implements CopilotClientRepositoryInterface
{
    private const TABLE = 'glpi_plugin_uxconnector_copilotclients';

    public function all(): array
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }
        $rows = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'ORDER' => ['id DESC']]) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function create(string $name, string $providerType, int $authConfigReference = 0): array
    {
        global $DB;
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Agent name must not be empty.');
        }
        if (!in_array($providerType, ['local_glpi', 'glpi_hl_api'], true)) {
            throw new \InvalidArgumentException('Unsupported Copilot provider type.');
        }
        if ($providerType === 'glpi_hl_api' && $authConfigReference <= 0) {
            throw new \InvalidArgumentException('A remote GLPI HL connection is required for this provider.');
        }

        $plainKey = 'uxc_' . bin2hex(random_bytes(32));
        $ok = $DB->insert(self::TABLE, [
            'name' => mb_substr($name, 0, 128),
            'api_key_hash' => hash('sha256', $plainKey),
            'provider_type' => $providerType,
            'auth_config_reference' => max(0, $authConfigReference),
            'is_active' => 1,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod' => date('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            throw new \RuntimeException('Unable to create Copilot agent.');
        }
        return ['id' => (int) $DB->insertId(), 'key' => $plainKey];
    }

    public function setActive(int $id, bool $active): void
    {
        global $DB;
        if ($id <= 0 || !$DB->update(self::TABLE, ['is_active' => $active ? 1 : 0, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id])) {
            throw new \RuntimeException('Unable to update Copilot agent.');
        }
    }

    public function delete(int $id): void
    {
        global $DB;
        if ($id <= 0 || !$DB->delete(self::TABLE, ['id' => $id])) {
            throw new \RuntimeException('Unable to delete Copilot agent.');
        }
    }
}
