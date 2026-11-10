<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\Persistence;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Enum\SemanticEngineConfigStatus;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\ValueObject\SemanticEngineConfigId;

final class SemanticEngineConfigPersistenceMapper
{
    /** @param array<string, mixed> $row */
    public function toDomain(array $row): SemanticEngineConfig
    {
        foreach (
            [
            'id', 'name', 'auth_config_reference', 'model', 'inference_path',
            'batch_size', 'confidence_threshold', 'temperature',
            'classifier_version', 'status', 'date_creation', 'date_mod',
            ] as $field
        ) {
            if (!array_key_exists($field, $row)) {
                throw new \RuntimeException(sprintf(
                    'Cannot hydrate SemanticEngineConfig: missing "%s" field.',
                    $field,
                ));
            }
        }

        $status = SemanticEngineConfigStatus::tryFrom((string) $row['status']);
        if ($status === null) {
            throw new \RuntimeException(sprintf(
                'Corrupted SemanticEngineConfig row #%s: unknown status "%s".',
                (string) $row['id'],
                (string) $row['status'],
            ));
        }

        return SemanticEngineConfig::reconstitute(
            new SemanticEngineConfigId((int) $row['id']),
            (string) $row['name'],
            (int) $row['auth_config_reference'],
            (string) $row['model'],
            (string) $row['inference_path'],
            (int) $row['batch_size'],
            (float) $row['confidence_threshold'],
            (float) $row['temperature'],
            (string) $row['classifier_version'],
            $status,
            new DateTimeImmutable((string) $row['date_creation']),
            new DateTimeImmutable((string) $row['date_mod']),
        );
    }

    /** @return array<string, int|float|string> */
    public function toPersistence(SemanticEngineConfig $config): array
    {
        $data = [
            'name' => $config->name(),
            'auth_config_reference' => $config->authConfigReference(),
            'model' => $config->model(),
            'inference_path' => $config->inferencePath(),
            'batch_size' => $config->batchSize(),
            'confidence_threshold' => $config->confidenceThreshold(),
            'temperature' => $config->temperature(),
            'classifier_version' => $config->classifierVersion(),
            'status' => $config->status()->value,
        ];

        if ($config->id() !== null) {
            $data['id'] = $config->id()->value();
        }

        return $data;
    }

    public function assignGeneratedId(SemanticEngineConfig $config, int $generatedId): void
    {
        $config->assignId(new SemanticEngineConfigId($generatedId));
    }
}
