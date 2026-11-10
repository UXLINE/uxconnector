<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Application\Engine\Service\ListAuthConfigsService;
use GlpiPlugin\Uxconnector\Application\Sync\Command\UpdateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Service\GetSyncJobForEditService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\UpdateSyncJobService;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\UnknownEntityPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Application\Sync\Service\TargetFieldsCatalogInterface;
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
use GlpiPlugin\Uxconnector\Presentation\Controller\AbstractController;
use Session;

final class SyncJobEditController extends AbstractController
{
    public function __construct(
        private readonly GetSyncJobForEditService $getSyncJob,
        private readonly UpdateSyncJobService $updateSyncJob,
        private readonly ListAuthConfigsService $listAuthConfigs,
        private readonly ?TargetFieldsCatalogInterface $fieldsCatalog = null
    ) {
    }

    /**
     * @param array<string, mixed> $request
     */
    public function showForm(array $request): void
    {
        try {
            $id = new SyncJobId((int) ($request['id'] ?? 0));
            $syncJob = $this->getSyncJob->execute($id);

            if ($syncJob->status === 'active') {
                Session::addMessageAfterRedirect(
                    __(
                        'Pause the synchronization job before modifying it.',
                        'uxconnector',
                    ),
                    false,
                    WARNING,
                );

                $this->redirect(
                    '/plugins/uxconnector/front/syncjob.view.php?id='
                    . $syncJob->id,
                );
                return;
            }

            $policy = new TargetFieldPolicy();
            $targetFieldCatalog = [];

            foreach (TargetItemtype::cases() as $itemtype) {
                $targetFieldCatalog[] = [
                    'value' => $itemtype->value,
                    'label' => $itemtype->label(),
                    'writable' => array_merge(
                        $policy->allowedWritableFields($itemtype),
                        ($this->fieldsCatalog?->writableFor(ltrim($itemtype->glpiClassName(), '\\')) ?? [])
                    ),
                    'matchable' => $policy->allowedMatchFields($itemtype),
                ];
            }

            $this->render('sync/syncjob/edit.html.twig', [
                'syncjob' => $syncJob,
                'schedule' => json_decode($syncJob->paginationJson, true)['options'] ?? [],
                'authConfigs' => $this->listAuthConfigs->execute(),
                'targetItemtypes' => TargetItemtype::cases(),
                'targetFieldCatalog' => $targetFieldCatalog,
            ]);
        } catch (\Throwable $exception) {
            Session::addMessageAfterRedirect(
                $exception->getMessage(),
                false,
                ERROR,
            );

            $this->redirect(
                '/plugins/uxconnector/front/syncjob.php',
            );
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    public function update(array $request): void
    {
        $idValue = (int) ($request['id'] ?? 0);

        try {
            $id = new SyncJobId($idValue);

            [$direction, $trigger] = $this->resolveMode(
                (string) ($request['interaction_mode'] ?? ''),
            );

            $resourcePath = trim(
                (string) ($request['resource_path'] ?? ''),
            );

            if (
                $trigger === InteractionTrigger::WEBHOOK
                && $resourcePath === ''
            ) {
                $resourcePath = '/webhook';
            }

            if (
                $direction === SyncDirection::OUTBOUND
                && $resourcePath === ''
            ) {
                $resourcePath = '/outbound';
            }

            $fieldMappings = $direction === SyncDirection::INBOUND
               ? $this->parseFieldMappings(
                   (string) ($request['field_mappings_json'] ?? '[]'),
               )
               : [];

            $pagination = (
                $direction === SyncDirection::INBOUND
            && $trigger === InteractionTrigger::POLLING
            )
               ? $this->parsePagination(
                   (string) ($request['pagination_json'] ?? '{}'),
               )
               : PaginationConfig::none();

            $pagination = (new JobScheduleRequestParser())->apply($pagination, $request);

            $collectionPath = $direction === SyncDirection::OUTBOUND
               ? CollectionPath::root()
               : new CollectionPath(
                   (string) ($request['collection_path'] ?? '$'),
               );

            $entityAssignment = $this->parseEntityAssignment(
                (string) ($request['entity_assignment_json'] ?? 'null'),
            );

            $outbound = $direction === SyncDirection::OUTBOUND
               ? $this->parseOutbound(
                   (string) ($request['outbound_json'] ?? '{}'),
               )
               : OutboundConfig::none();

            $command = new UpdateSyncJobCommand(
                id: $id,
                name: new SyncJobName(
                    (string) ($request['name'] ?? ''),
                ),
                authConfigReference: new AuthConfigReference(
                    (int) ($request['auth_config_reference'] ?? 0),
                ),
                direction: $direction,
                resourcePath: new ResourcePath($resourcePath),
                targetItemtype: TargetItemtype::from(
                    (string) ($request['target_itemtype'] ?? ''),
                ),
                matchField: new MatchField(
                    (string) ($request['match_field'] ?? ''),
                ),
                externalIdField: new ExternalIdField(
                    (string) ($request['external_id_field'] ?? ''),
                ),
                fieldMappings: $fieldMappings,
                entityAssignmentStrategy: $entityAssignment,
                collectionPath: $collectionPath,
                paginationConfig: $pagination,
                triggerType: $trigger,
                externalObjectType: trim(
                    (string) ($request['external_object_type'] ?? ''),
                ) ?: (string) ($request['target_itemtype'] ?? 'record'),
                outboundConfig: $outbound,
            );

            $saved = $this->updateSyncJob->execute($command);

            $savedId = $saved->id();

            if ($savedId === null) {
                throw new \LogicException(
                    'Updated synchronization job has no identifier.',
                );
            }

            Session::addMessageAfterRedirect(
                __('Interaction updated.', 'uxconnector'),
            );

            $this->redirect(
                '/plugins/uxconnector/front/syncjob.view.php?id='
                . $savedId->value(),
            );
        } catch (\Throwable $exception) {
            Session::addMessageAfterRedirect(
                $exception->getMessage(),
                false,
                ERROR,
            );

            $this->redirect(
                '/plugins/uxconnector/front/syncjob.edit.php?id='
                . $idValue,
            );
        }
    }

    /**
     * @return array{SyncDirection, InteractionTrigger}
     */
    private function resolveMode(string $mode): array
    {
        return match ($mode) {
            'inbound_polling' => [
                SyncDirection::INBOUND,
                InteractionTrigger::POLLING,
            ],
            'inbound_webhook' => [
                SyncDirection::INBOUND,
                InteractionTrigger::WEBHOOK,
            ],
            'outbound_glpi_event' => [
                SyncDirection::OUTBOUND,
                InteractionTrigger::GLPI_EVENT,
            ],
            default => throw new \RuntimeException(
                'Unknown interaction mode.',
            ),
        };
    }

    /**
     * @return list<FieldMapping>
     */
    private function parseFieldMappings(string $json): array
    {
        $payload = $this->decodeJson($json, 'Field mappings');

        if (!array_is_list($payload)) {
            throw new \RuntimeException(
                'Field mappings JSON must contain a list.',
            );
        }

        $result = [];

        foreach ($payload as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException(
                    'Each field mapping must be a JSON object.',
                );
            }

            $source = trim(
                (string) ($row['source_field'] ?? ''),
            );
            $target = trim(
                (string) ($row['target_field'] ?? ''),
            );

            if ($source === '' || $target === '') {
                throw new \RuntimeException(
                    'Each field mapping requires source_field and target_field.',
                );
            }

            $transform = trim(
                (string) ($row['transform'] ?? ''),
            );

            $result[] = new FieldMapping(
                $source,
                $target,
                (bool) ($row['required'] ?? false),
                $transform !== '' ? $transform : null,
            );
        }

        return $result;
    }

    private function parsePagination(string $json): PaginationConfig
    {
        $payload = $this->decodeJson(
            $json,
            'Pagination configuration',
        );

        $type = (string) ($payload['type'] ?? 'none');
        $options = $payload['options'] ?? [];

        if (!is_array($options)) {
            throw new \RuntimeException(
                'Pagination options must be a JSON object.',
            );
        }

        return PaginationConfig::fromArray(
            $type,
            $options,
        );
    }

    private function parseEntityAssignment(
        string $json
    ): ?EntityAssignmentStrategy {
        $trimmed = trim($json);

        if (
            $trimmed === ''
            || $trimmed === 'null'
            || $trimmed === '{}'
        ) {
            return null;
        }

        $payload = $this->decodeJson(
            $json,
            'Entity assignment',
        );

        $type = (string) ($payload['type'] ?? '');

        if ($type === 'fixed') {
            return EntityAssignmentStrategy::fixed(
                (int) ($payload['entity_id'] ?? 0),
            );
        }

        if ($type !== 'mapped') {
            throw new \RuntimeException(
                'Entity assignment type must be fixed or mapped.',
            );
        }

        $rawMapping = $payload['mapping'] ?? [];

        if (!is_array($rawMapping)) {
            throw new \RuntimeException(
                'Entity mapping must be a JSON object.',
            );
        }

        $mapping = [];

        foreach ($rawMapping as $value => $entityId) {
            $mapping[(string) $value] = (int) $entityId;
        }

        $unknownPolicy = UnknownEntityPolicy::tryFrom(
            (string) ($payload['unknown_policy'] ?? 'fail'),
        ) ?? UnknownEntityPolicy::FAIL;

        return EntityAssignmentStrategy::mapped(
            (string) ($payload['source_field'] ?? ''),
            $mapping,
            $unknownPolicy,
            isset($payload['default_entity_id'])
              ? (int) $payload['default_entity_id']
              : null,
        );
    }

    private function parseOutbound(string $json): OutboundConfig
    {
        $trimmed = trim($json);

        if ($trimmed === '' || $trimmed === '{}') {
            return OutboundConfig::none();
        }

        return OutboundConfig::fromArray(
            $this->decodeJson(
                $json,
                'Outbound configuration',
            ),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJson(
        string $json,
        string $label
    ): array {
        try {
            $decoded = json_decode(
                $json,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                $label . ' must contain valid JSON.',
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                $label . ' must contain a JSON object or array.',
            );
        }

        return $decoded;
    }
}
