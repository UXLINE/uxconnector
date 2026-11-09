<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Template\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\ActivateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Command\CreateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ActivateSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\CreateSyncJobService;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\OutboundConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use GlpiPlugin\Uxconnector\Domain\Template\Repository\ConnectorTemplateRepositoryInterface;

/**
 * Turns a JobTemplate into a fully resolved SyncJob exactly once.
 *
 * ExecuteSyncJobService never receives a template or placeholder. This service
 * is the single boundary where template parameters become concrete runtime
 * configuration.
 */
final class InstantiateJobTemplateService
{
    public function __construct(
        private readonly ConnectorTemplateRepositoryInterface $templateRepository,
        private readonly CreateSyncJobService $createSyncJob,
        private readonly ActivateSyncJobService $activateSyncJob,
        private readonly AuthConfigRepositoryInterface $authConfigRepository,
        private readonly TemplateStringResolver $resolver
    ) {}

    /**
     * @param array<string,mixed> $parameters
     */
    public function execute(
        string $connectorId,
        string $jobTemplateId,
        int $authConfigId,
        array $parameters = [],
        ?int $fixedEntityId = null,
        bool $activate = true
    ): int {
        $connector = $this->templateRepository->findById($connectorId);
        if ($connector === null) {
            throw new \RuntimeException(sprintf('Unknown connector template "%s".', $connectorId));
        }

        $template = $connector->findJob($jobTemplateId);
        if ($template === null) {
            throw new \RuntimeException(sprintf('Unknown job template "%s" for connector "%s".', $jobTemplateId, $connectorId));
        }
        if (!$template->available || $template->retired) {
            throw new \RuntimeException(sprintf('Job template "%s" is not available in this plugin version.', $jobTemplateId));
        }

        $authConfig = $this->authConfigRepository->findById(new AuthConfigId($authConfigId));
        if ($authConfig === null) {
            throw new \RuntimeException(sprintf('Authentication configuration #%d does not exist.', $authConfigId));
        }
        if ($authConfig->connectorId() !== 'custom' && $authConfig->connectorId() !== $connector->id) {
            throw new \RuntimeException(sprintf(
                'Connection #%d is associated with connector "%s", not "%s".',
                $authConfigId,
                $authConfig->connectorId(),
                $connector->id,
            ));
        }

        if (!in_array($authConfig->authMethod()->value, $connector->authMethods, true)) {
            throw new \RuntimeException(sprintf(
                'Authentication method "%s" is not supported by connector template "%s".',
                $authConfig->authMethod()->value,
                $connector->id,
            ));
        }
        if ($activate && !$authConfig->isUsable()) {
            throw new \RuntimeException('The selected authentication configuration must be tested and active before activating a catalogue job.');
        }
        if ($template->direction === SyncDirection::OUTBOUND->value && $fixedEntityId === null) {
            throw new \RuntimeException('A fixed GLPI entity/source scope is required for a standard outbound interaction.');
        }

        $resolvedParams = $this->resolver->resolveParameters($template->parameters, $parameters);
        $resolvedPagination = $this->resolver->resolveRecursive($template->pagination, $resolvedParams);
        $resolvedMappings = $this->resolver->resolveRecursive($template->fieldMappings, $resolvedParams);
        $resolvedOutbound = $this->resolver->resolveRecursive($template->outbound, $resolvedParams);

        if (!is_array($resolvedPagination) || !is_array($resolvedMappings) || !is_array($resolvedOutbound)) {
            throw new \RuntimeException('Template resolution produced an invalid job definition.');
        }

        $fieldMappings = [];
        foreach ($resolvedMappings as $mapping) {
            if (!is_array($mapping)) {
                throw new \RuntimeException('Template field mapping must be an object.');
            }
            $fieldMappings[] = new FieldMapping(
                (string) ($mapping['source'] ?? ''),
                (string) ($mapping['target'] ?? ''),
                (bool) ($mapping['required'] ?? false),
                isset($mapping['transform']) && (string) $mapping['transform'] !== '' ? (string) $mapping['transform'] : null,
            );
        }

        $paginationType = (string) ($resolvedPagination['type'] ?? 'none');
        $paginationOptions = is_array($resolvedPagination['options'] ?? null) ? $resolvedPagination['options'] : [];

        $job = $this->createSyncJob->execute(new CreateSyncJobCommand(
            new SyncJobName($this->resolver->resolveString($template->name, $resolvedParams)),
            new AuthConfigReference($authConfigId),
            SyncDirection::from($template->direction),
            new ResourcePath($this->resolver->resolveString($template->resourcePath, $resolvedParams)),
            TargetItemtype::from($template->targetItemtype),
            new MatchField($this->resolver->resolveString($template->matchField, $resolvedParams)),
            new ExternalIdField($this->resolver->resolveString($template->externalIdField, $resolvedParams)),
            $fieldMappings,
            $fixedEntityId !== null ? EntityAssignmentStrategy::fixed($fixedEntityId) : null,
            new CollectionPath($this->resolver->resolveString($template->collectionPath, $resolvedParams)),
            PaginationConfig::fromArray($paginationType, $paginationOptions),
            $template->id,
            $template->version,
            InteractionTrigger::from($template->triggerType),
            $template->externalObjectType ?? $template->targetItemtype,
            OutboundConfig::fromArray($resolvedOutbound),
            $template->triggerType === InteractionTrigger::WEBHOOK->value ? bin2hex(random_bytes(32)) : null,
        ));

        $id = $job->id();
        if ($id === null) {
            throw new \RuntimeException('Instantiated SyncJob was not persisted.');
        }

        if ($activate) {
            $this->activateSyncJob->execute(new ActivateSyncJobCommand($id));
        }

        return $id->value();
    }
}
