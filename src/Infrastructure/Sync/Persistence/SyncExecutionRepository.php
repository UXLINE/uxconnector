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

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final class SyncExecutionRepository implements SyncExecutionRepositoryInterface, \GlpiPlugin\Uxconnector\Domain\Sync\Repository\LatestSyncExecutionRepositoryInterface
{
    public function __construct(
        private readonly SyncExecutionPersistenceMapper $mapper
    ) {}

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

        if (!$model->update($data)) {
            throw new \RuntimeException(sprintf(
                'Failed to update SyncExecution #%d.',
                $execution->id()->value(),
            ));
        }

        return $this->mustFindById($execution->id());
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
