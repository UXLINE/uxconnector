<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use DBmysql;
use GlpiPlugin\Uxconnector\Application\Sync\DTO\BatchProcessResult;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\ExecutionAwareBatchProcessorInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncFailure;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncErrorCategory;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncFailureRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;

/**
 * INDIGO migration adapter: reads pseudo-telecom records stored as GLPI 10
 * Location search rows and normalizes them into GLPI 11 Location + Line.
 *
 * Additional business fields are written through the Fields plugin generated
 * container classes, never by ALTER/INSERT against GLPI core tables.
 */
final class IndigoTelecomLocationBatchProcessor implements ExecutionAwareBatchProcessorInterface
{
    private const TEMPLATE_ID = GlpiRestV1TelecomLocationResourcePathResolver::TEMPLATE_ID;

    public function __construct(
        private readonly DBmysql $db,
        private readonly AuthConfigRepositoryInterface $authConfigs,
        private readonly GlpiRestV1LocationSearchOptionCatalog $catalog,
        private readonly ObjectLinkRepositoryInterface $objectLinks,
        private readonly GlpiTargetFieldsWriter $fieldsWriter
    ) {
    }

    public function supports(SyncJob $syncJob): bool
    {
        return $syncJob->templateId() === self::TEMPLATE_ID;
    }

    public function process(SyncJob $syncJob, array $items): BatchProcessResult
    {
        return $this->processWithContext($syncJob, $items, null, 0, null, null);
    }

    public function processWithContext(
        SyncJob $syncJob,
        array $items,
        ?SyncExecutionId $executionId,
        int $page,
        ?string $cursor,
        ?SyncFailureRepositoryInterface $failureRepository
    ): BatchProcessResult {
        $auth = $this->authConfigs->findById(new AuthConfigId($syncJob->authConfigReference()->value()));
        if ($auth === null) {
            throw new \RuntimeException('GLPI REST v1 authentication configuration not found.');
        }
        $map = $this->catalog->resolve($auth);
        $this->catalog->assertTelecomSourceOptionsAvailable($map);
        // Fail before writing any GLPI object when the target Fields schema is incomplete.
        $this->fieldsWriter->assertAvailable('Location', [
            'IdBaseParcs',
            'Début Exploitation',
        ]);
        $this->fieldsWriter->assertAvailable('Line', [
            'Compte facturant',
            'Abonnement',
            'Entité Inpark',
            'Code DA',
            'Code DA Comptable',
            'Code ETS',
            'Code DB',
            'Code NA',
            'Code NB',
            'Date de création métier',
            'Date de suspension',
            'Date de résiliation',
            'Code RDS',
            'Date de renouvellement',
        ]);
        $processed = $failed = $created = $updated = $skipped = $warnings = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                $failed++;
                continue;
            }
            try {
                $sourceId = $this->value($item, $map, 'source_id');
                $fullName = $this->value($item, $map, 'full_name');
                $lineNumber = $this->value($item, $map, 'line_number');
                if ($sourceId === '' || $fullName === '') {
                    $failed++;
                    continue;
                }

                if ($lineNumber !== '') {
                    [$action, $lineId, $recordWarnings] = $this->upsertLine(
                        $item,
                        $map,
                        $syncJob,
                        $sourceId,
                        $executionId,
                        $page,
                        $cursor,
                        $failureRepository
                    );
                    $warnings += $recordWarnings;
                    $this->rememberLink(
                        $syncJob,
                        $sourceId,
                        TargetItemtype::LINE,
                        $lineId,
                        'glpi_rest_v1_location_telecom_line'
                    );
                } else {
                    [$action, $locationId] = $this->upsertLocation($item, $map, $fullName);
                    $this->rememberLink(
                        $syncJob,
                        $sourceId,
                        TargetItemtype::LOCATION,
                        $locationId,
                        'glpi_rest_v1_location_telecom_site'
                    );
                }
                $processed++;
                match ($action) {
                    'create' => $created++,
                    'update' => $updated++,
                    default => $skipped++,
                };
            } catch (\Throwable $e) {
                $failed++;
                error_log('[UXConnector][INDIGO telecom] ' . $e->getMessage());
            }
        }

        return new BatchProcessResult($processed, $failed, $created, $updated, $skipped, $warnings);
    }

    /** @return array{0:string,1:int} */
    private function upsertLocation(array $row, array $map, string $fullName): array
    {
        $name = trim(explode(' > ', $fullName, 2)[0]);
        $data = [
            'name' => $name,
            'code' => $this->value($row, $map, 'site_code'),
            'address' => $this->value($row, $map, 'address'),
            'postcode' => $this->value($row, $map, 'postcode'),
            'town' => $this->value($row, $map, 'town'),
            'latitude' => $this->decimal($this->value($row, $map, 'latitude')),
            'longitude' => $this->decimal($this->value($row, $map, 'longitude')),
        ];
        $data = $this->nonEmpty($data);
        $existing = null;
        if (($data['code'] ?? '') !== '') {
            $existing = $this->firstRow('glpi_locations', ['code' => $data['code']]);
        }
        $existing ??= $this->firstRow('glpi_locations', ['name' => $name]);
        $location = new \Location();
        if ($existing === null) {
            $id = (int) $location->add($data + ['entities_id' => 0]);
            if ($id <= 0) {
                throw new \RuntimeException('Unable to create target Location "' . $name . '".');
            }
            $coreChanged = true;
            $action = 'create';
        } else {
            $id = (int) $existing['id'];
            $delta = $this->delta($existing, $data);
            $coreChanged = $delta !== [];
            if ($coreChanged && !$location->update(['id' => $id] + $delta)) {
                throw new \RuntimeException('Unable to update target Location #' . $id . '.');
            }
            $action = $coreChanged ? 'update' : 'skip';
        }
        $fieldsChanged = $this->fieldsWriter->write('Location', $id, [
            'IdBaseParcs' => $this->value($row, $map, 'id_base_parcs'),
            'Début Exploitation' => $this->date($this->value($row, $map, 'operation_start')),
        ]);
        if ($action === 'skip' && $fieldsChanged) {
            $action = 'update';
        }
        return [$action, $id];
    }

    /** @return array{0:string,1:int,2:int} */
    private function upsertLine(
        array $row,
        array $map,
        SyncJob $syncJob,
        string $sourceId,
        ?SyncExecutionId $executionId,
        int $page,
        ?string $cursor,
        ?SyncFailureRepositoryInterface $failureRepository
    ): array {
        $number = $this->value($row, $map, 'line_number');
        $fullName = $this->value($row, $map, 'full_name');
        $parentName = trim(explode(' > ', $fullName, 2)[0]);
        $location = $this->firstRow('glpi_locations', ['name' => $parentName]);
        if ($location === null) {
            $obj = new \Location();
            $locationId = (int) $obj->add(['name' => $parentName, 'entities_id' => 0]);
            if ($locationId <= 0) {
                throw new \RuntimeException('Unable to create parent Location "' . $parentName . '".');
            }
        } else {
            $locationId = (int) $location['id'];
        }

        $stateId = $this->resolveDropdown('glpi_states', '\\State', $this->value($row, $map, 'status'));
        $operatorId = $this->resolveDropdown('glpi_lineoperators', '\\LineOperator', $this->value(
            $row,
            $map,
            'operator'
        ));
        $data = $this->nonEmpty([
            'name' => $number,
            'caller_num' => $number,
            'caller_name' => $this->value($row, $map, 'caller_name'),
            'comment' => $this->value($row, $map, 'comment'),
            'locations_id' => $locationId,
            'states_id' => $stateId,
            'lineoperators_id' => $operatorId,
        ]);
        $existing = $this->firstRow('glpi_lines', ['caller_num' => $number]);
        $line = new \Line();
        if ($existing === null) {
            $id = (int) $line->add($data + ['entities_id' => 0]);
            if ($id <= 0) {
                throw new \RuntimeException('Unable to create target Line "' . $number . '".');
            }
            $action = 'create';
        } else {
            $id = (int) $existing['id'];
            $delta = $this->delta($existing, $data);
            if ($delta !== [] && !$line->update(['id' => $id] + $delta)) {
                throw new \RuntimeException('Unable to update target Line #' . $id . '.');
            }
            $action = $delta !== [] ? 'update' : 'skip';
        }

        $fieldsChanged = $this->fieldsWriter->write('Line', $id, [
            'Compte facturant' => $this->value($row, $map, 'billing_account'),
            'Abonnement' => $this->value($row, $map, 'subscription'),
            'Entité Inpark' => $this->value($row, $map, 'inpark_entity'),
            'Code DA' => $this->value($row, $map, 'code_da'),
            'Code DA Comptable' => $this->value($row, $map, 'code_da_accounting'),
            'Code ETS' => $this->value($row, $map, 'code_ets'),
            'Code DB' => $this->value($row, $map, 'code_db'),
            'Code NA' => $this->value($row, $map, 'code_na'),
            'Code NB' => $this->value($row, $map, 'code_nb'),
            'Date de création métier' => $this->date($this->value($row, $map, 'business_creation_date')),
            'Date de suspension' => $this->date($this->value($row, $map, 'suspension_date')),
            'Date de résiliation' => $this->date($this->value($row, $map, 'termination_date')),
            'Code RDS' => $this->value($row, $map, 'code_rds'),
            'Date de renouvellement' => $this->date($this->value($row, $map, 'renewal_date')),
        ]);
        if ($action === 'skip' && $fieldsChanged) {
            $action = 'update';
        }

        // Each enrichment is isolated: a financial failure must not prevent equipment
        // linking or SIM import, and a SIM failure must not roll back the Line itself.
        $recordWarnings = 0;
        $missingPayloadColumns = $this->catalog->missingTelecomPayloadColumns($row, $map);
        if ($missingPayloadColumns !== []) {
            $recordWarnings++;
            $this->recordSourcePayloadWarning(
                $syncJob,
                $sourceId,
                $id,
                $missingPayloadColumns,
                $executionId,
                $page,
                $cursor,
                $failureRepository,
            );
        }
        $equipment = null;
        try {
            $equipment = $this->upsertEquipment($row, $map, $locationId);
        } catch (\Throwable $e) {
            $recordWarnings++;
            $this->recordEnrichmentWarning(
                $syncJob,
                $sourceId,
                $id,
                'equipment',
                $e,
                $executionId,
                $page,
                $cursor,
                $failureRepository
            );
        }
        if ($equipment !== null) {
            try {
                $this->linkEquipmentToLine($equipment, $id);
            } catch (\Throwable $e) {
                $recordWarnings++;
                $this->recordEnrichmentWarning(
                    $syncJob,
                    $sourceId,
                    $id,
                    'equipment_link',
                    $e,
                    $executionId,
                    $page,
                    $cursor,
                    $failureRepository
                );
            }
        }
        $rawDeliveryDate = $this->value($row, $map, 'delivery_date');
        $rawEquipmentValue = $this->value($row, $map, 'equipment_value');
        if ($rawDeliveryDate !== '' || $rawEquipmentValue !== '') {
            try {
                $deliveryDate = $this->date($rawDeliveryDate);
                if ($rawDeliveryDate !== '' && $deliveryDate === '') {
                    throw new TelecomEnrichmentException('FINANCIAL_DELIVERY_DATE_INVALID');
                }
                $equipmentValue = $this->money($rawEquipmentValue);
                if ($equipment === null) {
                    throw new TelecomEnrichmentException('FINANCIAL_EQUIPMENT_MISSING');
                }
                $this->upsertInfocom($equipment['itemtype'], $equipment['id'], $deliveryDate, $equipmentValue);
            } catch (\Throwable $e) {
                $recordWarnings++;
                $this->recordEnrichmentWarning(
                    $syncJob,
                    $sourceId,
                    $id,
                    'financial',
                    $e,
                    $executionId,
                    $page,
                    $cursor,
                    $failureRepository
                );
            }
        }
        try {
            $this->upsertSimcard($row, $map, $locationId, $id, $equipment);
        } catch (\Throwable $e) {
            $recordWarnings++;
            $this->recordEnrichmentWarning(
                $syncJob,
                $sourceId,
                $id,
                'simcard',
                $e,
                $executionId,
                $page,
                $cursor,
                $failureRepository
            );
        }

        return [$action, $id, $recordWarnings];
    }

    /**
     * An absent forcedisplay key is different from a returned empty value.
     * Log only allowlisted field labels, never their potentially sensitive values.
     *
     * @param list<string> $missing
     */
    private function recordSourcePayloadWarning(
        SyncJob $job,
        string $sourceId,
        int $lineId,
        array $missing,
        ?SyncExecutionId $executionId,
        int $page,
        ?string $cursor,
        ?SyncFailureRepositoryInterface $repository
    ): void {
        $code = 'SOURCE_OPTION_NOT_RETURNED';
        $labels = implode(', ', $missing);
        error_log('[UXConnector][INDIGO telecom][source] ' . $code . ' source #' . $sourceId . ': ' . $labels);
        if ($repository === null || $executionId === null || $job->id() === null) {
            return;
        }
        $repository->add(new SyncFailure(
            $executionId,
            $job->id(),
            'glpi_rest_v1_location_telecom',
            $sourceId,
            null,
            'Line',
            $lineId,
            'source_payload',
            'warning',
            SyncErrorCategory::POST_PROCESSING,
            $code,
            'GLPI 10 source omitted forcedisplay columns: ' . $labels . '.',
            null,
            null,
            1,
            $page,
            $cursor,
        ));
    }

    private function recordEnrichmentWarning(
        SyncJob $job,
        string $sourceId,
        int $lineId,
        string $operation,
        \Throwable $exception,
        ?SyncExecutionId $executionId,
        int $page,
        ?string $cursor,
        ?SyncFailureRepositoryInterface $repository
    ): void {
        // The exception code is an allowlisted internal reason; never store field
        // contents (ICCID, PUK, financial amounts) in the incident history.
        $fallbackCode = match ($operation) {
            'simcard' => 'SIMCARD_ENRICHMENT_FAILED',
            'equipment_link' => 'EQUIPMENT_LINK_FAILED',
            'financial' => 'FINANCIAL_ENRICHMENT_FAILED',
            default => 'EQUIPMENT_ENRICHMENT_FAILED',
        };
        $errorCode = $exception instanceof TelecomEnrichmentException
            ? $exception->reasonCode()
            : $fallbackCode;
        $message = sprintf('Telecom %s enrichment failed for source #%s (%s).', $operation, $sourceId, $errorCode);
        error_log('[UXConnector][INDIGO telecom][' . $operation . '] ' . $errorCode . ' source #' . $sourceId);
        if ($repository === null || $executionId === null || $job->id() === null) {
            return;
        }
        $repository->add(new SyncFailure(
            $executionId,
            $job->id(),
            'glpi_rest_v1_location_telecom',
            $sourceId,
            null,
            'Line',
            $lineId,
            $operation,
            'warning',
            SyncErrorCategory::POST_PROCESSING,
            $errorCode,
            $message,
            substr($exception::class, 0, 255),
            null,
            1,
            $page,
            $cursor,
        ));
    }

    /** @return array{itemtype:string,id:int}|null */
    private function upsertEquipment(array $row, array $map, int $locationId): ?array
    {
        $label = $this->value($row, $map, 'equipment');
        $serial = $this->value($row, $map, 'equipment_serial');
        if ($label === '' && $serial === '') {
            return null;
        }
        $isNetwork = preg_match('/^OTD\s*\d*/i', $label) === 1;
        $class = $isNetwork ? '\NetworkEquipment' : '\Phone';
        $table = $isNetwork ? 'glpi_networkequipments' : 'glpi_phones';
        $itemtype = $isNetwork ? 'NetworkEquipment' : 'Phone';
        $existing = $serial !== '' ? $this->firstRow($table, ['serial' => $serial]) : null;
        $existing ??= $this->firstRow($table, ['name' => $label, 'locations_id' => $locationId]);
        $data = $this->nonEmpty(['name' => $label !== '' ? $label : $serial, 'serial' => $serial,
                'locations_id' => $locationId]);
        $obj = \GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\NativeItemFactory::create($class);
        if ($existing === null) {
            $equipmentId = (int) $obj->add($data + ['entities_id' => 0]);
            if ($equipmentId <= 0) {
                throw new TelecomEnrichmentException('EQUIPMENT_CREATE_REJECTED');
            }
        } else {
            $equipmentId = (int) $existing['id'];
            $delta = $this->delta($existing, $data);
            if ($delta !== [] && !$obj->update(['id' => $equipmentId] + $delta)) {
                throw new TelecomEnrichmentException('EQUIPMENT_UPDATE_REJECTED');
            }
        }

        return ['itemtype' => $itemtype, 'id' => $equipmentId];
    }

    /** @param array{itemtype:string,id:int} $equipment */
    private function linkEquipmentToLine(array $equipment, int $lineId): void
    {
        $where = ['lines_id' => $lineId, 'itemtype' => $equipment['itemtype'], 'items_id' => $equipment['id']];
        if ($this->firstRow('glpi_items_lines', $where) !== null) {
            return;
        }
        $relation = new \Item_Line();
        if (!(int) $relation->add($where)) {
            throw new TelecomEnrichmentException('LINE_EQUIPMENT_LINK_REJECTED');
        }
    }

    /**
     * A SIM definition is a separate GLPI device model. A zero foreign key is
     * not considered a valid substitute for a model in this importer.
     * The generic model is created only once per entity and is clearly named.
     */
    private function resolveSimcardModel(int $entityId): int
    {
        $designation = 'UXConnector - SIM model unspecified';
        $existing = $this->firstRow('glpi_devicesimcards', [
            'designation' => $designation, 'entities_id' => $entityId,
        ]);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        $model = new \DeviceSimcard();
        $id = (int) $model->add(['designation' => $designation, 'entities_id' => $entityId]);
        if ($id <= 0) {
            throw new TelecomEnrichmentException('SIM_MODEL_CREATE_REJECTED');
        }
        return $id;
    }

    /** @param array{itemtype:string,id:int}|null $equipment */
    private function upsertSimcard(array $row, array $map, int $locationId, int $lineId, ?array $equipment): void
    {
        $serial = $this->value($row, $map, 'sim_number');
        $puk = $this->value($row, $map, 'puk');
        if ($serial === '' && $puk === '') {
            return;
        }
        // Never create an unidentifiable SIM from only a secret PUK.
        if ($serial === '') {
            throw new TelecomEnrichmentException('SIM_ICCID_MISSING');
        }
        $location = $this->firstRow('glpi_locations', ['id' => $locationId]);
        if ($location === null) {
            throw new TelecomEnrichmentException('SIM_LOCATION_NOT_FOUND');
        }
        $entityId = (int) ($location['entities_id'] ?? 0);
        $modelId = $this->resolveSimcardModel($entityId);
        $existing = $this->firstRow('glpi_items_devicesimcards', ['serial' => $serial]);
        // A SIM may be moved between equipment, but we never silently steal a
        // card already attached to a different line: investigate that conflict.
        if (
            $existing !== null && (int) ($existing['lines_id'] ?? 0) > 0
            && (int) $existing['lines_id'] !== $lineId
        ) {
            throw new TelecomEnrichmentException('SIM_ALREADY_ATTACHED_TO_OTHER_LINE');
        }
        $data = [
            'serial' => $serial,
            'locations_id' => $locationId,
            'lines_id' => $lineId,
            'devicesimcards_id' => $modelId,
        ];
        if ($puk !== '') {
            // GLPI protects PIN/PUK behind an additional right; never report a
            // successful import if that right is missing and GLPI drops it.
            if (
                class_exists('Session') && defined('UPDATE')
                && !\Session::haveRightsOr('devicesimcard_pinpuk', [UPDATE])
            ) {
                throw new TelecomEnrichmentException('SIM_PUK_WRITE_RIGHT_MISSING');
            }
            $data['puk'] = $puk;
        }
        if ($equipment !== null) {
            $data['itemtype'] = $equipment['itemtype'];
            $data['items_id'] = $equipment['id'];
        }
        $sim = new \Item_DeviceSimcard();
        if ($existing === null) {
            // Item_DeviceSimcard supports unattached inventory records, but
            // still needs a valid device model and line association.
            $id = (int) $sim->add($data + [
                'entities_id' => $entityId,
                'itemtype' => '',
                'items_id' => 0,
            ]);
            if ($id <= 0) {
                throw new TelecomEnrichmentException('SIM_CREATE_REJECTED');
            }
            return;
        }
        if ((int) ($existing['entities_id'] ?? $entityId) !== $entityId) {
            throw new TelecomEnrichmentException('SIM_ENTITY_MISMATCH');
        }
        $delta = $this->delta($existing, $data);
        if ($delta !== [] && !$sim->update(['id' => (int) $existing['id']] + $delta)) {
            throw new TelecomEnrichmentException('SIM_UPDATE_REJECTED');
        }
    }

    private function upsertInfocom(string $itemtype, int $itemId, string $deliveryDate, string $value): void
    {
        if ($deliveryDate === '' && $value === '') {
            return;
        }
        $existing = $this->firstRow('glpi_infocoms', ['itemtype' => $itemtype, 'items_id' => $itemId]);
        $data = $this->nonEmpty(['itemtype' => $itemtype, 'items_id' => $itemId, 'delivery_date' => $deliveryDate,
                'value' => $value]);
        $obj = new \Infocom();
        if ($existing === null) {
            if (!$obj->add($data)) {
                throw new TelecomEnrichmentException('FINANCIAL_CREATE_REJECTED');
            }
            return;
        }
        $delta = $this->delta($existing, $data);
        if ($delta !== [] && !$obj->update(['id' => (int) $existing['id']] + $delta)) {
            throw new TelecomEnrichmentException('FINANCIAL_UPDATE_REJECTED');
        }
    }

    private function rememberLink(
        SyncJob $job,
        string $sourceId,
        TargetItemtype $type,
        int $itemId,
        string $externalType
    ): void {
        $jobId = $job->id();
        if ($jobId === null) {
            return;
        }
        $externalId = new ExternalId($sourceId);
        $existing = $this->objectLinks->findByExternalId($job->authConfigReference(), $externalType, $externalId);
        if ($existing !== null) {
            if ($existing->itemId() === $itemId && $existing->itemtype() === $type) {
                $existing->touch();
                $this->objectLinks->save($existing);
                return;
            }
            // The target may have been deleted and recreated during a replay. Repair the
            // equivalence instead of keeping an ObjectLink that points to a stale GLPI id.
            if ($existing->id() !== null) {
                $this->objectLinks->delete($existing->id());
            }
        }
        $this->objectLinks->save(ObjectLink::create(
            $jobId,
            $job->authConfigReference(),
            $externalId,
            $type,
            $itemId,
            $externalType
        ));
    }

    private function resolveDropdown(string $table, string $class, string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $row = $this->firstRow($table, ['name' => $name]);
        if ($row !== null) {
            return (int) $row['id'];
        }
        if (!class_exists($class)) {
            return 0;
        }
        $obj = \GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\NativeItemFactory::create($class);
        $id = (int) $obj->add(['name' => $name]);
        return max(0, $id);
    }

    private function value(array $row, array $map, string $logical): string
    {
        $id = $map[$logical] ?? null;
        if ($id === null) {
            return '';
        }
        $value = $row[(string) $id] ?? $row[$id] ?? null;
        if (is_array($value)) {
            $value = $value['name'] ?? $value['value'] ?? '';
        }
        if (!is_scalar($value)) {
            return '';
        }
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\$#\$.*$/s', '', $text) ?? $text;
        return trim($text);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function nonEmpty(array $data): array
    {
        return array_filter($data, static fn(mixed $v): bool => $v !== null && $v !== '');
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $wanted @return array<string,mixed> */
    private function delta(array $existing, array $wanted): array
    {
        return \GlpiPlugin\Uxconnector\Domain\Sync\Policy\ExistingValuePolicy::additions($existing, $wanted);
    }

    /** @param array<string,mixed> $where @return array<string,mixed>|null */
    private function firstRow(string $table, array $where): ?array
    {
        if (!$this->db->tableExists($table)) {
            return null;
        }
        $rows = $this->db->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1]);
        foreach ($rows as $row) {
            return $row;
        }
        return null;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Formats observed in GLPI 10, GLPI exports and REST fields.
        // DateTime may silently roll invalid dates forward; reject such input.
        foreach (
            ['!d/m/Y', '!d-m-Y', '!d.m.Y', '!Y-m-d', '!Y-m-d H:i:s', '!Y-m-d H:i:s.u', '!Y-m-d\TH:i:sP',
                '!Y-m-d\TH:i:s.uP'] as $format
        ) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (
                $date instanceof \DateTimeImmutable && ($errors === false || (
                        $errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ) {
                return $date->format('Y-m-d');
            }
        }
        return '';
    }

    private function decimal(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }

    private function money(string $value): string
    {
        $value = trim(str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $value));
        if ($value === '') {
            return '';
        }
        // Remove recognized money units only. Silently stripping arbitrary letters
        // could turn a malformed source value into an incorrect Infocom amount.
        $value = preg_replace('/\b(?:EUR|HT|TTC)\b|€/iu', '', $value) ?? '';
        $value = preg_replace('/\s+/u', '', trim($value)) ?? '';
        if (!preg_match('/^-?[0-9][0-9.,]*$/D', $value)) {
            throw new TelecomEnrichmentException('FINANCIAL_AMOUNT_INVALID');
        }
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $group = $decimal === ',' ? '.' : ',';
            $value = str_replace($group, '', $value);
            $value = str_replace($decimal, '.', $value);
        } elseif ($comma !== false) {
            $value = str_replace(',', '.', $value);
        } elseif (substr_count($value, '.') > 1) {
            throw new TelecomEnrichmentException('FINANCIAL_AMOUNT_AMBIGUOUS');
        }
        if (!preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new TelecomEnrichmentException('FINANCIAL_AMOUNT_INVALID');
        }
        return $value;
    }
}
