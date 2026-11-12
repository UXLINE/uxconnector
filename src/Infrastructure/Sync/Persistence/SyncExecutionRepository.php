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

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use DBmysql;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncExecutionBusyException;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ExecutionStopRequestRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final class SyncExecutionRepository implements
    SyncExecutionRepositoryInterface,
    \GlpiPlugin\Uxconnector\Domain\Sync\Repository\LatestSyncExecutionRepositoryInterface
{
    public function __construct(
        private readonly SyncExecutionPersistenceMapper $mapper,
        private readonly DBmysql $db
    ) {
    }

    public function save(SyncExecution $execution): SyncExecution
    {
        $data = $this->mapper->toPersistence($execution);
        $model = new SyncExecutionItem();

        if ($execution->id() === null) {
            $generatedId = $model->add($data);

            if ($generatedId === false) {
                throw new \RuntimeException('Failed to persist a new SyncExecution.');
            }

            $this->mapper->assignGeneratedId($execution, (int) $generatedId);

            $executionId = $execution->id();
            if ($executionId === null) {
                throw new \LogicException(
                    'Persisted SyncExecution has no identifier.',
                );
            }

            return $this->mustFindById($executionId);
        }

        $previous = $execution->persistedStatus();
        if ($previous !== null && $previous !== $execution->status()) {
            return $this->saveTransition($execution, $execution->id(), $previous, $data);
        }

        if (!$model->update($data)) {
            throw new \RuntimeException(sprintf(
                'Failed to update SyncExecution #%d.',
                $execution->id()->value(),
            ));
        }

        return $this->mustFindById($execution->id());
    }

    /**
     * Status transitions use a compare-and-set so stale worker or UI snapshots
     * cannot resurrect an execution or overwrite an accepted cancellation.
     *
     * @param array<string,int|string|null> $data
     */
    private function saveTransition(
        SyncExecution $execution,
        SyncExecutionId $id,
        SyncExecutionStatus $previous,
        array $data
    ): SyncExecution {
        unset($data['id']);
        $where = ['id' => $id->value(), 'status' => $previous->value];
        $cancel = ExecutionStopRequestRepositoryInterface::CANCELLATION_REQUESTED;

        if ($previous === SyncExecutionStatus::RUNNING && $execution->status()->isFinished()) {
            $this->db->update(SyncExecutionItem::getTable(), $data, $where + ['stop_requested' => ['<>', $cancel]]);
            if ($this->db->affectedRows() === 1) {
                return $this->mustFindById($id);
            }

            $execution->cancel();
            $cancelled = $this->mapper->toPersistence($execution);
            unset($cancelled['id']);
            $this->db->update(
                SyncExecutionItem::getTable(),
                $cancelled + ['stop_requested' => 0],
                $where + ['stop_requested' => $cancel]
            );
        } else {
            $this->db->update(SyncExecutionItem::getTable(), $data, $where);
        }

        if ($this->db->affectedRows() !== 1) {
            throw new SyncExecutionBusyException('The execution changed before the requested action could be applied.');
        }

        return $this->mustFindById($id);
    }

    public function requestCancellation(SyncExecutionId $id, SyncJobId $jobId): SyncExecution
    {
        $table = SyncExecutionItem::getTable();
        $where = ['id' => $id->value(), 'plugin_uxconnector_syncjobs_id' => $jobId->value()];
        $cancellableWithoutWorker = $where + [
            'status' => [SyncExecutionStatus::PENDING->value, SyncExecutionStatus::INTERRUPTED->value],
        ];
        $cancelled = [
            'status' => SyncExecutionStatus::CANCELLED->value,
            'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'resume_available' => 0,
            'resume_path' => null,
            'error_message' => null,
            'stop_requested' => 0,
        ];

        $this->db->update($table, $cancelled, $cancellableWithoutWorker);
        if ($this->db->affectedRows() === 0) {
            $this->db->update(
                $table,
                ['stop_requested' => ExecutionStopRequestRepositoryInterface::CANCELLATION_REQUESTED],
                $where + ['status' => SyncExecutionStatus::RUNNING->value]
            );
            // The worker may have paused between the two conditional updates.
            $this->db->update($table, $cancelled, $cancellableWithoutWorker);
        }

        $execution = $this->findById($id);
        if ($execution === null || $execution->syncJobId()->value() !== $jobId->value()) {
            throw new SyncExecutionBusyException('Execution not found for this interaction.');
        }
        if ($execution->status() !== SyncExecutionStatus::CANCELLED && !$execution->cancellationRequested()) {
            throw new SyncExecutionBusyException('This execution is no longer cancellable.');
        }
        return $execution;
    }

    public function findById(SyncExecutionId $id): ?SyncExecution
    {
        $model = new SyncExecutionItem();

        if (!$model->getFromDB($id->value())) {
            return null;
        }

        return $this->mapper->toDomain($model->fields);
    }

    /**
     * @return list<SyncExecution>
     */
    public function findBySyncJobId(SyncJobId $syncJobId): array
    {
        $model = new SyncExecutionItem();

        $rows = $model->find(
            ['plugin_uxconnector_syncjobs_id' => $syncJobId->value()],
            ['started_at DESC'],
        );

        return array_values(array_map(
            fn(array $row): SyncExecution => $this->mapper->toDomain($row),
            $rows,
        ));
    }


    public function findLatestBySyncJobId(SyncJobId $id): ?SyncExecution
    {
        $rows = (new SyncExecutionItem())->find(
            ['plugin_uxconnector_syncjobs_id' => $id->value()],
            ['started_at DESC', 'id DESC'],
            1,
        );
        foreach ($rows as $row) {
            return $this->mapper->toDomain($row);
        }
        return null;
    }

    public function findPending(int $limit = 20): array
    {
        $model = new SyncExecutionItem();
        $rows = $model->find(['status' => 'pending'], ['started_at ASC'], max(1, min(100, $limit)));
        return array_values(array_map(fn(array $row): SyncExecution => $this->mapper->toDomain($row), $rows));
    }

    public function findRunning(int $limit = 100): array
    {
        $model = new SyncExecutionItem();
        $rows = $model->find(['status' => 'running'], ['started_at ASC'], max(1, min(500, $limit)));
        return array_values(array_map(fn(array $row): SyncExecution => $this->mapper->toDomain($row), $rows));
    }

    public function hasOpenExecution(SyncJobId $syncJobId): bool
    {
        $model = new SyncExecutionItem();
        $rows = $model->find([
            'plugin_uxconnector_syncjobs_id' => $syncJobId->value(),
            'status' => ['pending', 'running'],
        ], [], 1);
        return $rows !== [];
    }

    public function delete(SyncExecutionId $id): void
    {
        $model = new SyncExecutionItem();

        // RULE-032 (adapted) : purge, not soft-delete - consistent
        // with every other Repository in this plugin.
        $model->delete(['id' => $id->value()], true);
    }

    private function mustFindById(SyncExecutionId $id): SyncExecution
    {
        $execution = $this->findById($id);

        if ($execution === null) {
            throw new \RuntimeException(sprintf(
                'SyncExecution #%d was just persisted but could not be reloaded.',
                $id->value(),
            ));
        }

        return $execution;
    }
}
