<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncCheckpointRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/** GLPI persistence for opaque cursor checkpoints. */
final class SyncCheckpointRepository implements SyncCheckpointRepositoryInterface
{
    private const TABLE = 'glpi_plugin_uxconnector_sync_checkpoints';

    public function __construct(private readonly DBmysql $db) {}

    public function findCursor(SyncJobId $syncJobId): ?string
    {
        $rows = $this->db->request([
            'SELECT' => ['cursor_value'],
            'FROM' => self::TABLE,
            'WHERE' => ['plugin_uxconnector_syncjobs_id' => $syncJobId->value()],
            'LIMIT' => 1,
        ]);

        foreach ($rows as $row) {
            $cursor = trim((string) ($row['cursor_value'] ?? ''));
            return $cursor !== '' ? $cursor : null;
        }

        return null;
    }

    public function saveCursor(SyncJobId $syncJobId, string $cursor): void
    {
        $cursor = trim($cursor);
        if ($cursor === '') {
            throw new \InvalidArgumentException('A synchronization checkpoint cursor must not be empty.');
        }

        $existing = $this->db->request([
            'SELECT' => ['id'],
            'FROM' => self::TABLE,
            'WHERE' => ['plugin_uxconnector_syncjobs_id' => $syncJobId->value()],
            'LIMIT' => 1,
        ]);

        foreach ($existing as $row) {
            $ok = $this->db->update(
                self::TABLE,
                [
                    'cursor_value' => $cursor,
                    'date_mod' => date('Y-m-d H:i:s'),
                ],
                ['id' => (int) $row['id']],
            );
            if (!$ok) {
                throw new \RuntimeException('Unable to update synchronization checkpoint.');
            }
            return;
        }

        $ok = $this->db->insert(self::TABLE, [
            'plugin_uxconnector_syncjobs_id' => $syncJobId->value(),
            'cursor_value' => $cursor,
            'date_mod' => date('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            throw new \RuntimeException('Unable to persist synchronization checkpoint.');
        }
    }

    public function delete(SyncJobId $syncJobId): void
    {
        $this->db->delete(self::TABLE, ['plugin_uxconnector_syncjobs_id' => $syncJobId->value()]);
    }
}
