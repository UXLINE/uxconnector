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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Entity;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Event\SyncJobCreatedEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\OutboundConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;

/**
 * SyncJob is the aggregate root of the Sync bounded context.
 *
 * It represents a configured, repeatable synchronization: which
 * AuthConfig (Engine) to authenticate through, which direction data
 * moves in, which GLPI itemtype is the target, and how external
 * fields map onto it. It never executes anything itself -
 * ExecuteSyncJobService (Application) orchestrates a run and records
 * its outcome as a separate SyncExecution aggregate.
 *
 * Two distinct concepts govern how a run decides between updating an
 * existing GLPI item and creating a new one - a distinction
 * introduced to fix a real collision risk a code review identified
 * (see ObjectLink's doc-block):
 *
 * - `externalIdField` names a field of the *raw* item returned by the
 *   external system (e.g. "id", "sys_id") - its value is this
 *   record's stable identity, remembered by ObjectLink once
 *   reconciled. It is checked first, on every run.
 * - `matchField` names a *mapped* GLPI column (e.g. "serial") used
 *   only the very first time a given external_id is encountered, to
 *   decide between adopting an already-existing GLPI record (LINK)
 *   or creating a brand new one (CREATE). Every subsequent run for
 *   the same external_id bypasses matchField entirely, going straight
 *   to the GLPI item ObjectLink already knows about.
 *
 * `entityAssignmentStrategy` is optional (null by default) : when
 * absent, a run never sets `entities_id` explicitly and GLPI's own
 * default behaviour applies - appropriate for a single-entity
 * instance. When present (see EntityAssignmentStrategy), every
 * synchronized record is assigned to a specific GLPI entity, either
 * fixed or derived from a field of the raw source item - the gap a
 * code review flagged as significant for any multi-entity deployment.
 *
 * RULE-ENTITY-001 to 005 apply: private properties, invariants
 * enforced here, explicit business methods, no SQL, real behaviour.
 */
final class SyncJob
{
    /**
     * @var list<DomainEvent>
     */
    private array $events = [];

    private ?SyncJobId $id = null;

    private SyncJobStatus $status;

    /**
     * @var list<FieldMapping>
     */
    private array $fieldMappings;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    private CollectionPath $collectionPath;

    private PaginationConfig $paginationConfig;

    private ?string $templateId;

    private ?string $templateVersion;

    private InteractionTrigger $triggerType;

    private string $externalObjectType;

    private OutboundConfig $outboundConfig;

    private ?string $webhookKey;

    /**
     * @param list<FieldMapping> $fieldMappings
     */
    private function __construct(
        private SyncJobName $name,
        private AuthConfigReference $authConfigReference,
        private SyncDirection $direction,
        private ResourcePath $resourcePath,
        private TargetItemtype $targetItemtype,
        private MatchField $matchField,
        private ExternalIdField $externalIdField,
        array $fieldMappings,
        private ?EntityAssignmentStrategy $entityAssignmentStrategy = null,
        ?CollectionPath $collectionPath = null,
        ?PaginationConfig $paginationConfig = null,
        ?string $templateId = null,
        ?string $templateVersion = null,
        ?InteractionTrigger $triggerType = null,
        ?string $externalObjectType = null,
        ?OutboundConfig $outboundConfig = null,
        ?string $webhookKey = null
    ) {
        $this->fieldMappings = $fieldMappings;
        $this->collectionPath = $collectionPath ?? CollectionPath::root();
        $this->paginationConfig = $paginationConfig ?? PaginationConfig::none();
        $this->templateId = $templateId !== null && trim($templateId) !== '' ? trim($templateId) : null;
        $this->templateVersion = $templateVersion !== null && trim($templateVersion) !== '' ? trim(
            $templateVersion
        ) : null;
        $this->triggerType = $triggerType ?? InteractionTrigger::POLLING;
        $this->externalObjectType = self::normalizeExternalObjectType($externalObjectType ?? $targetItemtype->value);
        $this->outboundConfig = $outboundConfig ?? OutboundConfig::none();
        $this->webhookKey = self::normalizeWebhookKey($webhookKey);
        $this->assertMatchFieldIsMapped();
        $this->status = SyncJobStatus::DRAFT;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Creates a brand new SyncJob in DRAFT status.
     *
     * @param list<FieldMapping> $fieldMappings
     *
     * @throws InvalidSyncJobStateException if $matchField does not name
     *         the targetField of any FieldMapping in $fieldMappings.
     */
    public static function create(
        SyncJobName $name,
        AuthConfigReference $authConfigReference,
        SyncDirection $direction,
        ResourcePath $resourcePath,
        TargetItemtype $targetItemtype,
        MatchField $matchField,
        ExternalIdField $externalIdField,
        array $fieldMappings = [],
        ?EntityAssignmentStrategy $entityAssignmentStrategy = null,
        ?CollectionPath $collectionPath = null,
        ?PaginationConfig $paginationConfig = null,
        ?string $templateId = null,
        ?string $templateVersion = null,
        ?InteractionTrigger $triggerType = null,
        ?string $externalObjectType = null,
        ?OutboundConfig $outboundConfig = null,
        ?string $webhookKey = null
    ): self {
        $syncJob = new self(
            $name,
            $authConfigReference,
            $direction,
            $resourcePath,
            $targetItemtype,
            $matchField,
            $externalIdField,
            $fieldMappings,
            $entityAssignmentStrategy,
            $collectionPath,
            $paginationConfig,
            $templateId,
            $templateVersion,
            $triggerType,
            $externalObjectType,
            $outboundConfig,
            $webhookKey,
        );

        $syncJob->record(new SyncJobCreatedEvent($name, $direction));

        return $syncJob;
    }

    /**
     * Rehydrates an existing SyncJob from persistence. Never records
     * Domain Events.
     *
     * @param list<FieldMapping> $fieldMappings
     */
    public static function reconstitute(
        SyncJobId $id,
        SyncJobName $name,
        AuthConfigReference $authConfigReference,
        SyncDirection $direction,
        ResourcePath $resourcePath,
        TargetItemtype $targetItemtype,
        MatchField $matchField,
        ExternalIdField $externalIdField,
        array $fieldMappings,
        SyncJobStatus $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?EntityAssignmentStrategy $entityAssignmentStrategy = null,
        ?CollectionPath $collectionPath = null,
        ?PaginationConfig $paginationConfig = null,
        ?string $templateId = null,
        ?string $templateVersion = null,
        ?InteractionTrigger $triggerType = null,
        ?string $externalObjectType = null,
        ?OutboundConfig $outboundConfig = null,
        ?string $webhookKey = null
    ): self {
        $syncJob = new self(
            $name,
            $authConfigReference,
            $direction,
            $resourcePath,
            $targetItemtype,
            $matchField,
            $externalIdField,
            $fieldMappings,
            $entityAssignmentStrategy,
            $collectionPath,
            $paginationConfig,
            $templateId,
            $templateVersion,
            $triggerType,
            $externalObjectType,
            $outboundConfig,
            $webhookKey,
        );
        $syncJob->id = $id;
        $syncJob->status = $status;
        $syncJob->createdAt = $createdAt;
        $syncJob->updatedAt = $updatedAt;

        return $syncJob;
    }

    public function assignId(SyncJobId $id): void
    {
        if ($this->id !== null) {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::cannotAssignIdTwice();
        }

        $this->id = $id;
    }

    /**
     * Atomically replaces the editable configuration of this SyncJob.
     *
     * Active interactions must be paused before being reconfigured.
     * Validation is performed before any property is mutated.
     *
     * @param list<FieldMapping> $fieldMappings
     */
    public function reconfigure(
        SyncJobName $name,
        AuthConfigReference $authConfigReference,
        SyncDirection $direction,
        ResourcePath $resourcePath,
        TargetItemtype $targetItemtype,
        MatchField $matchField,
        ExternalIdField $externalIdField,
        array $fieldMappings,
        ?EntityAssignmentStrategy $entityAssignmentStrategy,
        CollectionPath $collectionPath,
        PaginationConfig $paginationConfig,
        InteractionTrigger $triggerType,
        string $externalObjectType,
        OutboundConfig $outboundConfig,
        ?string $webhookKey
    ): void {
        if ($this->status === SyncJobStatus::ACTIVE) {
            throw new \DomainException(
                'An active synchronization job must be paused before it can be modified.',
            );
        }

        $normalizedExternalObjectType = self::normalizeExternalObjectType(
            $externalObjectType,
        );

        $normalizedWebhookKey = self::normalizeWebhookKey($webhookKey);

        if (
            $direction === SyncDirection::INBOUND
            && $fieldMappings !== []
        ) {
            $matchFieldFound = false;

            foreach ($fieldMappings as $mapping) {
                if ($mapping->targetField() === $matchField->value()) {
                    $matchFieldFound = true;
                    break;
                }
            }

            if (!$matchFieldFound) {
                throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::matchFieldNotMapped(
                    $matchField,
                );
            }
        }

        if (
            $direction === SyncDirection::OUTBOUND
            && $entityAssignmentStrategy !== null
            && !$entityAssignmentStrategy->isFixed()
        ) {
            throw new \DomainException(
                'Outbound interactions support only a fixed GLPI entity as source scope.',
            );
        }

        $this->name = $name;
        $this->authConfigReference = $authConfigReference;
        $this->direction = $direction;
        $this->resourcePath = $resourcePath;
        $this->targetItemtype = $targetItemtype;
        $this->matchField = $matchField;
        $this->externalIdField = $externalIdField;
        $this->fieldMappings = $fieldMappings;
        $this->entityAssignmentStrategy = $entityAssignmentStrategy;
        $this->collectionPath = $collectionPath;
        $this->paginationConfig = $paginationConfig;
        $this->triggerType = $triggerType;
        $this->externalObjectType = $normalizedExternalObjectType;
        $this->outboundConfig = $outboundConfig;
        $this->webhookKey = $normalizedWebhookKey;

        $this->touch();
    }
    public function rename(SyncJobName $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function changeDirection(SyncDirection $direction): void
    {
        $this->direction = $direction;
        $this->touch();
    }

    public function changeResourcePath(ResourcePath $resourcePath): void
    {
        $this->resourcePath = $resourcePath;
        $this->touch();
    }

    public function changeTargetItemtype(TargetItemtype $targetItemtype): void
    {
        $this->targetItemtype = $targetItemtype;
        $this->touch();
    }

    /**
     * @throws InvalidSyncJobStateException if $matchField does not name
     *         the targetField of any currently configured FieldMapping.
     */
    public function changeMatchField(MatchField $matchField): void
    {
        $this->matchField = $matchField;
        $this->assertMatchFieldIsMapped();
        $this->touch();
    }

    public function changeExternalIdField(ExternalIdField $externalIdField): void
    {
        $this->externalIdField = $externalIdField;
        $this->touch();
    }

    /**
     * Replaces the full set of field mappings. Deliberately atomic
     * (no add/remove-one-at-a-time methods): a mapping list is a
     * single business concept, always validated and swapped as a
     * whole, so the aggregate is never left with a half-edited,
     * order-dependent configuration.
     *
     * @param list<FieldMapping> $fieldMappings
     *
     * @throws InvalidSyncJobStateException if the current MatchField no
     *         longer names the targetField of any mapping in the new list.
     */
    public function changeFieldMappings(array $fieldMappings): void
    {
        $this->fieldMappings = $fieldMappings;
        $this->assertMatchFieldIsMapped();
        $this->touch();
    }

    /**
     * @return list<FieldMapping>
     */
    public function fieldMappings(): array
    {
        return $this->fieldMappings;
    }

    /**
     * Activates the SyncJob, making it eligible for execution by
     * SyncJobCronTask. Requires at least one FieldMapping: an empty
     * mapping list would synchronize nothing.
     */
    public function activate(): void
    {
        if ($this->direction === SyncDirection::INBOUND && $this->fieldMappings === []) {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException
                ::cannotActivateWithoutFieldMappings();
        }

        if ($this->direction === SyncDirection::OUTBOUND && !$this->outboundConfig->isConfigured()) {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException
                ::cannotActivateWithoutOutboundConfig();
        }

        if (!in_array($this->status, [SyncJobStatus::DRAFT, SyncJobStatus::PAUSED], true)) {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::cannotActivateFromStatus(
                $this->status
            );
        }

        $this->status = SyncJobStatus::ACTIVE;
        $this->touch();
    }

    public function pause(): void
    {
        $this->status = SyncJobStatus::PAUSED;
        $this->touch();
    }

    public function markError(): void
    {
        $this->status = SyncJobStatus::ERROR;
        $this->touch();
    }

    public function isRunnable(): bool
    {
        return $this->status->isRunnable();
    }

    public function id(): ?SyncJobId
    {
        return $this->id;
    }

    public function name(): SyncJobName
    {
        return $this->name;
    }

    public function authConfigReference(): AuthConfigReference
    {
        return $this->authConfigReference;
    }

    public function direction(): SyncDirection
    {
        return $this->direction;
    }

    public function resourcePath(): ResourcePath
    {
        return $this->resourcePath;
    }

    public function targetItemtype(): TargetItemtype
    {
        return $this->targetItemtype;
    }

    public function matchField(): MatchField
    {
        return $this->matchField;
    }

    public function externalIdField(): ExternalIdField
    {
        return $this->externalIdField;
    }

    public function changeEntityAssignmentStrategy(?EntityAssignmentStrategy $entityAssignmentStrategy): void
    {
        $this->entityAssignmentStrategy = $entityAssignmentStrategy;
        $this->touch();
    }

    public function entityAssignmentStrategy(): ?EntityAssignmentStrategy
    {
        return $this->entityAssignmentStrategy;
    }

    public function collectionPath(): CollectionPath
    {
        return $this->collectionPath;
    }

    public function paginationConfig(): PaginationConfig
    {
        return $this->paginationConfig;
    }

    public function templateId(): ?string
    {
        return $this->templateId;
    }

    public function templateVersion(): ?string
    {
        return $this->templateVersion;
    }

    public function triggerType(): InteractionTrigger
    {
        return $this->triggerType;
    }

    public function externalObjectType(): string
    {
        return $this->externalObjectType;
    }

    public function outboundConfig(): OutboundConfig
    {
        return $this->outboundConfig;
    }

    public function webhookKey(): ?string
    {
        return $this->webhookKey;
    }

    public function isScheduledInteraction(): bool
    {
        return $this->triggerType->isScheduled();
    }

    public function status(): SyncJobStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    private function record(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    private static function normalizeExternalObjectType(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid external object type.');
        }
        return $value;
    }

    private static function normalizeWebhookKey(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::invalidWebhookKey();
        }
        return $value;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Enforces that MatchField always names a field this job's own
     * FieldMapping list actually produces - a MatchField pointing at
     * nothing would make GlpiItemWriter unable to ever find an
     * existing record, silently turning every run into pure
     * duplication.
     *
     * Deliberately a no-op while $fieldMappings is empty: a freshly
     * created SyncJob legitimately has no mapping yet (it cannot be
     * activated in that state either - see activate()), so MatchField
     * is allowed to reference a field that will only exist once the
     * author configures at least one FieldMapping.
     */
    private function assertMatchFieldIsMapped(): void
    {
        if ($this->direction !== SyncDirection::INBOUND || $this->fieldMappings === []) {
            return;
        }

        if ($this->matchField->value() === 'asset_identity') {
            $identityFields = ['uuid', 'serial', 'otherserial', 'name'];

            foreach ($this->fieldMappings as $mapping) {
                if (in_array($mapping->targetField(), $identityFields, true)) {
                    return;
                }
            }

            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException
                ::assetIdentityRequiresMappedIdentityField();
        }

        foreach ($this->fieldMappings as $mapping) {
            if ($mapping->targetField() === $this->matchField->value()) {
                return;
            }
        }

        throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::matchFieldNotMapped(
            $this->matchField
        );
    }
}
