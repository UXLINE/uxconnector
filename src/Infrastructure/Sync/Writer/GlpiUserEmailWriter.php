<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Writer;

use DBmysql;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime\SyncRuntimeGuard;

/** Email mapping fills missing email records; existing addresses and LDAP ownership are retained. */
final class GlpiUserEmailWriter
{
    /**
     * Creates the writer using the GLPI database connection.
     *
     * @param DBmysql $db Connection used for the transaction and row lock.
     */
    public function __construct(private readonly DBmysql $db)
    {
    }

    /**
     * Validates an incoming address after trimming it.
     *
     * @param mixed $value Raw value supplied by a source mapping.
     * @return string|null A validated address, or null for an absent or blank source value.
     * @throws \InvalidArgumentException If a nonblank source value is invalid.
     */
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

    /**
     * Fills a missing GLPI user email within a transaction that locks the user row.
     *
     * @param int $userId ID of the existing GLPI user.
     * @param string $email Address already validated by normalize().
     * @return bool True if an email was saved, false if an existing address was preserved.
     * @throws \Throwable If the transaction or native write fails.
     */
    public function write(int $userId, string $email): bool
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT `id` FROM `glpi_users` WHERE `id` = ? FOR UPDATE');
            try {
                $this->db->executeStatement($statement, [$userId], ['i']);
                $statement->bind_result($lockedUserId);
                if ($statement->fetch() !== true) {
                    throw new \RuntimeException('Target user is unavailable for email update.');
                }
            } finally {
                $statement->close();
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

    /**
     * Applies the email update while write() holds the user row lock.
     *
     * @param int $userId ID of the locked GLPI user.
     * @param string $email Validated email to set as the default.
     * @return bool True if an email was saved, false if an existing address was preserved.
     * @throws \RuntimeException If the email records are ambiguous or GLPI rejects the write.
     */
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
