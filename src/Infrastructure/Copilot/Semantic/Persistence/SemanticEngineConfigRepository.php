<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\Persistence;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Repository\SemanticEngineConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\ValueObject\SemanticEngineConfigId;

final class SemanticEngineConfigRepository implements SemanticEngineConfigRepositoryInterface
{
    public function __construct(private readonly SemanticEngineConfigPersistenceMapper $mapper)
    {
    }

    public function save(SemanticEngineConfig $config): SemanticEngineConfig
    {
        $data = $this->mapper->toPersistence($config);
        $model = new SemanticEngineConfigItem();

        if ($config->id() === null) {
            $generatedId = $model->add($data);
            if ($generatedId === false) {
                throw new \RuntimeException('Failed to persist a new SemanticEngineConfig.');
            }
            $this->mapper->assignGeneratedId($config, (int) $generatedId);
            $configId = $config->id();
            if ($configId === null) {
                throw new \LogicException(
                    'Persisted SemanticEngineConfig has no identifier.',
                );
            }

            return $this->mustFindById($configId);
        }

        if (!$model->update($data)) {
            throw new \RuntimeException(sprintf(
                'Failed to update SemanticEngineConfig #%d.',
                $config->id()->value(),
            ));
        }

        return $this->mustFindById($config->id());
    }

    public function findById(SemanticEngineConfigId $id): ?SemanticEngineConfig
    {
        $model = new SemanticEngineConfigItem();
        if (!$model->getFromDB($id->value())) {
            return null;
        }
        return $this->mapper->toDomain($model->fields);
    }

    public function findAll(): array
    {
        $model = new SemanticEngineConfigItem();
        $rows = $model->find();
        return array_values(array_map(
            fn(array $row): SemanticEngineConfig => $this->mapper->toDomain($row),
            $rows,
        ));
    }

    public function findActive(): ?SemanticEngineConfig
    {
        $model = new SemanticEngineConfigItem();
        $rows = $model->find(['status' => 'active'], [], 2);

        if (count($rows) > 1) {
            throw new \RuntimeException(
                'More than one active SemanticEngineConfig exists; configuration is ambiguous.',
            );
        }
        if ($rows === []) {
            return null;
        }

        $row = reset($rows);
        return is_array($row) ? $this->mapper->toDomain($row) : null;
    }

    public function delete(SemanticEngineConfigId $id): void
    {
        $model = new SemanticEngineConfigItem();
        if (!$model->delete(['id' => $id->value()], true)) {
            throw new \RuntimeException(sprintf(
                'Failed to delete SemanticEngineConfig #%d.',
                $id->value(),
            ));
        }
    }

    private function mustFindById(SemanticEngineConfigId $id): SemanticEngineConfig
    {
        $config = $this->findById($id);
        if ($config === null) {
            throw new \RuntimeException(sprintf(
                'SemanticEngineConfig #%d was just persisted but could not be reloaded.',
                $id->value(),
            ));
        }
        return $config;
    }
}
