<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Writer;

use DBmysql;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime\SyncRuntimeGuard;

/** Email mapping fills missing email records; existing addresses and LDAP ownership are retained. */
final class GlpiUserEmailWriter
{
    public function __construct(private readonly DBmysql $db)
    {
    }

    public function normalize(mixed $value): ?string
    {
        // Missing/blank source values never delete a working notification email.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value) || strlen($value) > 255 || !\GLPIMailer::validateAddress(trim($value))) {
            // Never include the external value in exception/log messages.
            throw new \InvalidArgumentException('Mapped user email is not a valid email address.');
        }
        return trim($value);
    }

    public function write(int $userId, string $email): bool
    {
        $this->db->beginTransaction();
        try {
            // Serialize email/default changes on this user, including writers
            // from different connections. No HTTP call occurs in this section.
            $result = $this->db->doQuery(sprintf('SELECT `id` FROM `glpi_users` WHERE `id` = %d FOR UPDATE', $userId));
            $row = $result === false ? false : $this->db->fetchAssoc($result);
            if (!is_array($row)) {
                throw new \RuntimeException('Target user is unavailable for email update.');
            }
            $changed = $this->writeLocked($userId, $email);
            $this->db->commit();
            return $changed;
        } catch (\Throwable $exception) {
            try {
                $this->db->rollback();
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }

    private function writeLocked(int $userId, string $email): bool
    {
        $rows = [];
        foreach (
            $this->db->request([
            'SELECT' => ['id', 'email', 'is_default'],
            'FROM' => 'glpi_useremails',
            'WHERE' => ['users_id' => $userId],
            'ORDER' => ['id ASC'],
            ]) as $row
        ) {
            $rows[] = $row;
        }
        $defaults = array_values(array_filter($rows, static fn(array $row): bool => (int) $row['is_default'] === 1));
        if (count($defaults) > 1) {
            throw new \RuntimeException('User has multiple default emails; automatic email update refused.');
        }
        $nonempty = array_filter($rows, static fn(array $row): bool => trim((string) $row['email']) !== '');
        if ($nonempty !== []) {
            return false; // Existing email/default/LDAP ownership is preserved.
        }
        $target = $defaults[0] ?? (count($rows) === 1 ? $rows[0] : null);
        if (count($rows) > 1 && $target === null) {
            throw new \RuntimeException('User has ambiguous empty email records; automatic update refused.');
        }
        $item = new \UserEmail();
        $success = SyncRuntimeGuard::run(static function () use ($item, $target, $userId, $email): bool {
            if ($target !== null) {
                return (bool) $item->update(['id' => (int) $target['id'], 'email' => $email, 'is_default' => 1]);
            }
            return $item->add(['users_id' => $userId, 'email' => $email, 'is_default' => 1]) !== false;
        });
        if (!$success) {
            throw new \RuntimeException('GLPI refused the user email update.');
        }
        return true;
    }
}
