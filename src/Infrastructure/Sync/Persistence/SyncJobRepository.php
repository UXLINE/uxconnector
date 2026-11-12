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

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\InteractionLookupRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final class SyncJobRepository implements SyncJobRepositoryInterface, InteractionLookupRepositoryInterface
{
    public function __construct(
        private readonly SyncJobPersistenceMapper $mapper
    ) {
    }

    public function save(SyncJob $syncJob): SyncJob
    {
        $data = $this->mapper->toPersistence($syncJob);
        $model = new SyncJobItem();

        if ($syncJob->id() === null) {
            $generatedId = $model->add($data);

            if ($generatedId === false) {
                throw new \RuntimeException('Failed to persist a new SyncJob.');
            }

            $this->mapper->assignGeneratedId($syncJob, (int) $generatedId);

            $syncJobId = $syncJob->id();
            if ($syncJobId === null) {
                throw new \LogicException(
                    'Persisted SyncJob has no identifier.',
                );
            }

            return $this->mustFindById($syncJobId);
        }

        if (
            $model->getFromDB($data['id'])
            && ($model->fields['webhook_key_hash'] ?? null) === $data['webhook_key_hash']
        ) {
            // Lifecycle and settings updates must not overwrite an independently rotated signing secret.
            unset($data['webhook_key_hash'], $data['webhook_token_secret'], $data['webhook_signing_secret']);
        }

        if (!$model->update($data)) {
            throw new \RuntimeException(sprintf(
                'Failed to update SyncJob #%d.',
                $syncJob->id()->value(),
            ));
        }

        return $this->mustFindById($syncJob->id());
    }

    public function findById(SyncJobId $id): ?SyncJob
    {
        $model = new SyncJobItem();

        if (!$model->getFromDB($id->value())) {
            return null;
        }

        return $this->mapper->toDomain($model->fields);
    }

    public function rotateWebhookSigningSecret(SyncJob $expected, string $newSecret): void
    {
        $model = new SyncJobItem();
        $id = $expected->id()?->value();
        if ($id === null || !$model->getFromDB($id)) {
            throw new \DomainException('The webhook no longer exists.');
        }

        $current = $this->mapper->toDomain($model->fields);
        if (
            $current->triggerType() !== InteractionTrigger::WEBHOOK
            || $current->direction() !== SyncDirection::INBOUND
            || $current->webhookSigningSecret() !== $expected->webhookSigningSecret()
            || $current->entityAssignmentStrategy() != $expected->entityAssignmentStrategy()
        ) {
            throw new \DomainException('The webhook has changed. Reopen the connection and try again.');
        }

        $fields = array_intersect_key($model->fields, array_flip([
            'id', 'trigger_type', 'direction', 'webhook_key_hash', 'webhook_signing_secret',
            'entity_assignment_strategy',
        ]));
        $db = $GLOBALS['DB'];
        $db->update(SyncJobItem::getTable(), [
            'webhook_signing_secret' => $this->mapper->protectWebhookSecret($newSecret),
        ], $fields);
        if ($db->affectedRows() !== 1) {
            throw new \DomainException('The webhook has changed. Reopen the connection and try again.');
        }
    }

    /**
     * @return list<SyncJob>
     */
    public function findAll(): array
    {
        $model = new SyncJobItem();

        return array_values(array_map(
            fn(array $row): SyncJob => $this->mapper->toDomain($row),
            $model->find(),
        ));
    }

    /**
     * @return list<SyncJob>
     */
    public function findRunnable(): array
    {
        $model = new SyncJobItem();

        $rows = $model->find([
            'status' => SyncJobStatus::ACTIVE->value,
            'trigger_type' => InteractionTrigger::POLLING->value,
            'direction' => SyncDirection::INBOUND->value,
        ]);

        return array_values(array_map(
            fn(array $row): SyncJob => $this->mapper->toDomain($row),
            $rows,
        ));
    }

    public function findByWebhookKey(string $webhookKey): ?SyncJob
    {
        $webhookKey = strtolower(trim($webhookKey));
        if ($webhookKey === '') {
            return null;
        }

        $rows = (new SyncJobItem())->find([
            'webhook_key_hash' => SyncJobPersistenceMapper::webhookKeyHash($webhookKey),
            'status' => SyncJobStatus::ACTIVE->value,
            'trigger_type' => InteractionTrigger::WEBHOOK->value,
            'direction' => SyncDirection::INBOUND->value,
        ], [], 1);

        foreach ($rows as $row) {
            return $this->mapper->toDomain($row);
        }

        return null;
    }

    public function findActiveForGlpiItem(string $targetItemtype): array
    {
        $rows = (new SyncJobItem())->find([
            'status' => SyncJobStatus::ACTIVE->value,
            'trigger_type' => InteractionTrigger::GLPI_EVENT->value,
            'direction' => SyncDirection::OUTBOUND->value,
            'target_itemtype' => $targetItemtype,
        ]);

        return array_values(array_map(
            fn(array $row): SyncJob => $this->mapper->toDomain($row),
            $rows,
        ));
    }

    public function delete(SyncJobId $id): void
    {
        $model = new SyncJobItem();

        // RULE-032 (adapted) : purge, not soft-delete - consistent
        // with AuthConfigRepository's convention for this plugin.
        $model->delete(['id' => $id->value()], true);
    }

    public function exists(SyncJobId $id): bool
    {
        $model = new SyncJobItem();

        return $model->getFromDB($id->value());
    }

    private function mustFindById(SyncJobId $id): SyncJob
    {
        $syncJob = $this->findById($id);

        if ($syncJob === null) {
            throw new \RuntimeException(sprintf(
                'SyncJob #%d was just persisted but could not be reloaded.',
                $id->value(),
            ));
        }

        return $syncJob;
    }
}
