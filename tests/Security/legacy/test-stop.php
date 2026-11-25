<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\ExecutionStopRequestRepository;

$root = $argv[1];
require $root . '/src/Domain/Sync/ValueObject/SyncExecutionId.php';
require $root . '/src/Domain/Sync/Repository/ExecutionStopRequestRepositoryInterface.php';
require $root . '/src/Infrastructure/Sync/Persistence/ExecutionStopRequestRepository.php';

final class DBmysql
{
    public array $rows = [
        1 => ['status' => 'running', 'stop_requested' => 0],
        2 => ['status' => 'completed', 'stop_requested' => 0],
        3 => ['status' => 'pending', 'stop_requested' => 1],
    ];
    public bool $fail = false;

    public function update(string $table, array $values, array $where): bool
    {
        if ($table !== 'glpi_plugin_uxconnector_syncexecutions' || !isset($where['id'], $where['status'])) {
            throw new LogicException('Missing update scope.');
        }
        if (($this->rows[$where['id']]['status'] ?? null) === $where['status']) {
            $this->rows[$where['id']]['stop_requested'] = $values['stop_requested'];
        }
        return true;
    }

    public function request(array $criteria): array
    {
        if ($this->fail) {
            throw new RuntimeException('Simulated database failure.');
        }
        if ($criteria['FROM'] !== 'glpi_plugin_uxconnector_syncexecutions') {
            throw new LogicException('Unexpected read table.');
        }
        $row = $this->rows[$criteria['WHERE']['id']] ?? null;
        return $row === null ? [] : [$row];
    }
}

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new DBmysql();
$repository = new ExecutionStopRequestRepository($db);
$running = new SyncExecutionId(1);
$pending = new SyncExecutionId(3);
verify(!$repository->isStopRequested($running), 'Unexpected initial stop flag.');
verify($repository->requestStop($running), 'Running execution was not signalled.');
verify($repository->requestStop($running), 'Repeated stop was not idempotent.');
verify(!$repository->requestStop(new SyncExecutionId(2)), 'Completed execution was signalled.');
$repository->clearStopRequest($running);
verify($repository->isStopRequested($running), 'Running execution was cleared.');
$repository->clearStopRequest($pending);
verify(!$repository->isStopRequested($pending), 'Pending execution kept a stale flag.');
verify(!$repository->isStopRequested(new SyncExecutionId(99)), 'Missing execution was signalled.');
$db->fail = true;
try {
    $repository->isStopRequested($running);
    throw new LogicException('Database read failure was swallowed.');
} catch (RuntimeException $error) {
    verify($error->getMessage() === 'Simulated database failure.', 'Unexpected database failure.');
}
echo "OK: running, completed, pending, idempotence, missing execution, database read failure.\n";
