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
    /** @var list<string> */
    private array $held = [];

    public function __construct(private readonly DBmysql $db) {}

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
        try {
            foreach ($keys as $key) {
                // Only a fixed-length hexadecimal hash enters SQL. DATABASE()
                // isolates independent GLPI databases on a shared MySQL server.
                $hash = hash('sha256', $key);
                $result = $this->db->doQuery("SELECT GET_LOCK(" . $this->nameExpression($hash) . ", 0) AS acquired");
                $row = $result === false ? false : $this->db->fetchAssoc($result);
                if (!is_array($row) || !array_key_exists('acquired', $row) || $row['acquired'] === null) {
                    throw new \RuntimeException('Unable to acquire synchronization worker ownership.');
                }
                if ((int) $row['acquired'] !== 1) {
                    $this->release();
                    return false;
                }
                $this->held[] = $hash;
            }
            $configured = getenv('UXCONNECTOR_MAX_CONCURRENT_JOBS');
            $maximum = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8]]);
            $maximum = $maximum === false ? 2 : $maximum;
            for ($slot = 1; $slot <= $maximum; ++$slot) {
                $hash = hash('sha256', 'slot:' . $slot);
                $result = $this->db->doQuery('SELECT GET_LOCK(' . $this->nameExpression($hash) . ', 0) AS acquired');
                $row = $result === false ? false : $this->db->fetchAssoc($result);
                if (!is_array($row) || !array_key_exists('acquired', $row) || $row['acquired'] === null) {
                    throw new \RuntimeException('Unable to acquire a synchronization worker slot.');
                }
                if ((int) $row['acquired'] === 1) {
                    $this->held[] = $hash;
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
        foreach ($held as $hash) {
            // A broken DB connection has already released its named locks.
            // Do not conceal the job's original exception during cleanup.
            try {
                $this->db->doQuery('SELECT RELEASE_LOCK(' . $this->nameExpression($hash) . ')');
            } catch (\Throwable) {
            }
        }
    }

    public function assertOwned(): void
    {
        if (count($this->held) !== 3) {
            throw new SyncExecutionOwnershipLostException('Synchronization worker no longer owns its locks.');
        }
        $checks = array_map(fn(string $hash): string => 'IS_USED_LOCK(' . $this->nameExpression($hash) . ') = CONNECTION_ID()', $this->held);
        $result = $this->db->doQuery('SELECT (' . implode(' AND ', $checks) . ') AS owned');
        $row = $result === false ? false : $this->db->fetchAssoc($result);
        if (!is_array($row) || (int) ($row['owned'] ?? 0) !== 1) {
            throw new SyncExecutionOwnershipLostException('Synchronization worker database ownership was lost.');
        }
    }

    private function nameExpression(string $hash): string
    {
        return "CONCAT('uxc29:', SHA1(CONCAT(DATABASE(), ':', '" . $hash . "')))";
    }
}
