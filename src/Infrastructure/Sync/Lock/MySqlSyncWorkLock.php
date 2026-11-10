<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Lock;

use DBmysql;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionOwnershipLostException;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Service\SyncWorkLockInterface;

/** MySQL connection-scoped ownership, automatically released on disconnect. */
final class MySqlSyncWorkLock implements SyncWorkLockInterface
{
    private const LOCK_PREFIX = 'uxc29:';
    private const NATIVE_HASH_LENGTH = 24;

    /** @var list<string> */
    private array $held = [];

    public function __construct(private readonly DBmysql $db)
    {
    }

    public function acquire(SyncJob $job): bool
    {
        if ($this->held !== []) {
            throw new \LogicException('Worker lock is already owned by this instance.');
        }
        $id = $job->id();
        if ($id === null) {
            throw new \LogicException('Worker lock requires a persisted job.');
        }
        // Jobs targeting the same source/GLPI type are intentionally serialized:
        // first-time reconciliation and attachment/comment writes may overlap.
        $keys = [
            'job:' . $id->value(),
            'target:' . $job->authConfigReference()->value() . ':' . $job->targetItemtype()->value,
        ];
        // GLPI prepends the database name. Respect both engines' lock name limits.
        $suffixLength = 1 + strlen(self::LOCK_PREFIX) + self::NATIVE_HASH_LENGTH;
        $databaseName = $this->db->dbdefault;
        if (
            mb_strlen($databaseName, 'UTF-8') + $suffixLength > 64
            && (
                strlen($databaseName) + $suffixLength > 192
                || $this->db->getVersionAndServer()['server'] !== 'MariaDB'
            )
        ) {
            throw new \RuntimeException(
                'The database name is too long for GLPI worker locks. '
                . 'Use at most 33 characters on MySQL or 161 bytes on MariaDB.'
            );
        }
        try {
            foreach ($keys as $key) {
                if (!$this->acquireKey($key)) {
                    $this->release();
                    return false;
                }
            }
            $configured = getenv('UXCONNECTOR_MAX_CONCURRENT_JOBS');
            $maximum = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1,
                        'max_range' => 8]]);
            $maximum = $maximum === false ? 2 : $maximum;
            for ($slot = 1; $slot <= $maximum; ++$slot) {
                if ($this->acquireKey('slot:' . $slot)) {
                    return true;
                }
            }
            $this->release();
            return false;
        } catch (\Throwable $exception) {
            $this->release();
            throw $exception;
        }
    }

    public function release(): void
    {
        $held = array_reverse($this->held);
        $this->held = [];
        foreach ($held as $name) {
            // A broken DB connection has already released its named locks.
            // Do not conceal the job's original exception during cleanup.
            try {
                $this->db->releaseLock($name);
            } catch (\Throwable) {
            }
        }
    }

    public function assertOwned(): void
    {
        if (count($this->held) !== 3) {
            throw new SyncExecutionOwnershipLostException('Synchronization worker no longer owns its locks.');
        }
        $checks = [];
        foreach ($this->held as $name) {
            $fullName = $this->db->dbdefault . '.' . $name;
            $checks[] = 'IS_USED_LOCK(' . $this->db->quote($fullName) . ') = CONNECTION_ID()';
        }
        $result = $this->db->doQuery('SELECT (' . implode(' AND ', $checks) . ') AS owned');
        $row = $result === false ? false : $this->db->fetchAssoc($result);
        if (!is_array($row) || (int) ($row['owned'] ?? 0) !== 1) {
            throw new SyncExecutionOwnershipLostException('Synchronization worker database ownership was lost.');
        }
    }

    private function acquireKey(string $key): bool
    {
        $hash = sha1($this->db->dbdefault . ':' . hash('sha256', $key));
        $name = self::LOCK_PREFIX . substr($hash, 0, self::NATIVE_HASH_LENGTH);
        if (!$this->db->getLock($name)) {
            return false;
        }

        $this->held[] = $name;
        return true;
    }
}
