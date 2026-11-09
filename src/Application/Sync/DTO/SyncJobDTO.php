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

namespace GlpiPlugin\Uxconnector\Application\Sync\DTO;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/**
 * Read-only, serializable representation of a SyncJob, safe to hand
 * to a template or a REST response.
 *
 * RULE-DTO-001/002/003 : immutable, the Entity is never exposed
 * directly, zero business logic - only a one-way conversion from the
 * aggregate's Value Objects/Enums to plain scalars.
 */
final readonly class SyncJobDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public string $authConfigReference,
        public string $direction,
        public string $directionLabel,
        public string $resourcePath,
        public string $targetItemtype,
        public string $targetItemtypeLabel,
        public string $matchField,
        public string $externalIdField,
        public string $collectionPath,
        public string $paginationType,
        public ?string $templateId,
        public ?string $templateVersion,
        public string $triggerType,
        public string $triggerTypeLabel,
        public string $externalObjectType,
        public ?string $webhookKey,
        public bool $outboundConfigured,
        public int $fieldMappingsCount,
        public string $entityAssignmentSummary,
        public string $status,
        public string $statusLabel,
        public bool $isRunnable,
        public string $createdAt,
        public string $updatedAt
    ) {}

    public static function fromEntity(SyncJob $syncJob): self
    {
        $id = $syncJob->id();

        if ($id === null) {
            throw new \LogicException(
                'Cannot build a SyncJobDTO from a SyncJob that has not been persisted yet.',
            );
        }

        return new self(
            id: $id->value(),
            name: $syncJob->name()->value(),
            authConfigReference: (string) $syncJob->authConfigReference()->value(),
            direction: $syncJob->direction()->value,
            directionLabel: $syncJob->direction()->label(),
            resourcePath: $syncJob->resourcePath()->value(),
            targetItemtype: $syncJob->targetItemtype()->value,
            targetItemtypeLabel: $syncJob->targetItemtype()->label(),
            matchField: $syncJob->matchField()->value(),
            externalIdField: $syncJob->externalIdField()->value(),
            collectionPath: $syncJob->collectionPath()->value(),
            paginationType: $syncJob->paginationConfig()->type()->value,
            templateId: $syncJob->templateId(),
            templateVersion: $syncJob->templateVersion(),
            triggerType: $syncJob->triggerType()->value,
            triggerTypeLabel: $syncJob->triggerType()->label(),
            externalObjectType: $syncJob->externalObjectType(),
            webhookKey: $syncJob->webhookKey(),
            outboundConfigured: $syncJob->outboundConfig()->isConfigured(),
            fieldMappingsCount: count($syncJob->fieldMappings()),
            entityAssignmentSummary: self::summarizeEntityAssignment($syncJob),
            status: $syncJob->status()->value,
            statusLabel: $syncJob->status()->label(),
            isRunnable: $syncJob->isRunnable(),
            createdAt: $syncJob->createdAt()->format('Y-m-d H:i:s'),
            updatedAt: $syncJob->updatedAt()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Human-readable, one-line summary of the entity assignment
     * strategy - kept here rather than a getter forwarding the raw
     * Value Object, since a template must never touch a Domain type
     * directly (RULE-048).
     *
     * Deliberately hardcoded French strings rather than __() : the
     * Application layer, like the Domain, never depends on GLPI
     * (RULE-CONNECTOR-005) - the same convention already followed by
     * every Domain enum's own label() method (see SyncDirection,
     * SyncJobStatus, ...). Actual translation for display is a
     * Presentation-layer concern.
     */
    private static function summarizeEntityAssignment(SyncJob $syncJob): string
    {
        $strategy = $syncJob->entityAssignmentStrategy();

        if ($strategy === null) {
            return 'Aucune (comportement GLPI par défaut)';
        }

        if ($strategy->isFixed()) {
            return sprintf('Fixe - entité #%d', $strategy->fixedEntityId());
        }

        return sprintf(
            'Déduite de « %s » (%d règle(s))',
            $strategy->sourceField(),
            count($strategy->valueToEntityId()),
        );
    }
}
