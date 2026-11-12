<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Security;

/** Persists only request fingerprints; a unique key arbitrates concurrent deliveries. */
final class WebhookReplayStore
{
    private const TABLE = 'glpi_plugin_uxconnector_webhookrequests';

    public function __construct(private readonly \DBmysql $db)
    {
    }

    /**
     * Reserves a verified request before any business write, using GLPI's query builder.
     * A failed processing attempt remains consumed: retry with a fresh signed timestamp.
     */
    public function claim(string $fingerprint, int $expiresAt, int $now): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || $expiresAt < $now) {
            throw new \InvalidArgumentException('Invalid webhook replay reservation.');
        }

        // This table is separate from job data and uses epoch seconds, independent of DB time zone.
        $this->db->delete(self::TABLE, ['expires_at' => ['<', $now]]);
        try {
            return $this->db->insert(self::TABLE, [
                'fingerprint' => $fingerprint,
                'expires_at' => $expiresAt,
            ]);
        } catch (\Throwable) {
            // Do not mistake a storage outage for successful authentication or expose SQL errors.
            $rows = $this->db->request([
                'SELECT' => ['fingerprint'],
                'FROM' => self::TABLE,
                'WHERE' => ['fingerprint' => $fingerprint],
                'LIMIT' => 1,
            ]);
            if (count($rows) === 1) {
                return false;
            }
            throw new \RuntimeException('Webhook replay protection is unavailable.');
        }
    }
}
