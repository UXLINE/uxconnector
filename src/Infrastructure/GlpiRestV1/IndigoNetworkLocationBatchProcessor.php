<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;

/** Turns network pseudo-locations into NetworkEquipment and true sites. */
final class IndigoNetworkLocationBatchProcessor extends AbstractLegacyLocationBatchProcessor
{
    protected function profile(): string
    {
        return LegacyLocationMigrationProfiles::NETWORK;
    }

    protected function processRecord(SyncJob $job, array $row, array $map, string $sourceId): array
    {
        $this->stage = 'source_validation';
        $full = $this->value($row, $map, 'full_name');
        if ($full === '') {
            throw new \RuntimeException('SOURCE_NAME_MISSING');
        }
        $parts = preg_split('/\s*>\s*/u', $full) ?: [];
        $siteName = trim((string) ($parts[0] ?? ''));
        if ($siteName === '') {
            throw new \RuntimeException('SOURCE_SITE_UNRESOLVED');
        }
        $siteCode = $this->value($row, $map, 'site_code');
        $siteData = $this->nonempty([
            'name' => $siteName,
            'code' => $siteCode,
            'address' => $this->value($row, $map, 'address'),
            'postcode' => $this->value($row, $map, 'postcode'),
            'town' => $this->value($row, $map, 'town'),
            'latitude' => $this->coordinate($this->value($row, $map, 'latitude')),
            'longitude' => $this->coordinate($this->value($row, $map, 'longitude')),
        ]);
        $this->stage = 'site_resolution';
        $existingSite = $siteCode !== '' ? $this->firstUnique('glpi_locations', ['code' => $siteCode]) : null;
        $existingSite ??= $this->firstUnique('glpi_locations', ['name' => $siteName]);
        $site = new \Location();
        if ($existingSite === null) {
            $siteId = (int) $site->add($siteData + ['entities_id' => 0]);
            if ($siteId <= 0) {
                throw new \RuntimeException('TARGET_SITE_CREATE_FAILED');
            }
            $siteAction = 'create';
        } else {
            $siteId = (int) $existingSite['id'];
            $delta = $this->delta($existingSite, $siteData);
            if ($delta !== [] && !$site->update(['id' => $siteId] + $delta)) {
                throw new \RuntimeException('TARGET_SITE_UPDATE_FAILED');
            }
            $siteAction = $delta === [] ? 'skip' : 'update';
        }
        $this->stage = 'site_fields';
        $siteFields = $this->fieldsWriter->write('Location', $siteId, [
            'IdBaseParcs' => $this->value($row, $map, 'site_base'),
            'Début Exploitation' => $this->dateOrEmpty($row, $map, 'operation_start'),
        ]);
        if ($siteFields && $siteAction === 'skip') {
            $siteAction = 'update';
        }
        $type = $this->value($row, $map, 'equipment_type');
        $model = $this->value($row, $map, 'equipment_model');
        $serial = $this->value($row, $map, 'serial');
        if ($type === '' && $model === '' && $serial === '') {
            return [$siteAction, $siteId, TargetItemtype::LOCATION, 'glpi_rest_v1_indigo_network_site'];
        }
        // Distinguish a true site from a pseudo-equipment through Fields,
        // not by copying all legacy Location records as new Locations.
        $name = trim((string) end($parts));
        if ($name === '' || ($name === $siteName && count($parts) === 1)) {
            $name = $serial !== '' ? $serial : ($model !== '' ? $model : $type);
        }
        $this->stage = 'equipment_resolution';
        $knownLink = $this->objectLinks->findByExternalId(
            $job->authConfigReference(),
            'glpi_rest_v1_indigo_network_equipment',
            new \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId($sourceId),
        );
        $existing = null;
        if ($knownLink !== null) {
            if ($knownLink->itemtype() !== TargetItemtype::NETWORK_EQUIPMENT) {
                throw new \RuntimeException('TARGET_LINK_TYPE_CONFLICT');
            }
            $existing = $this->firstUnique('glpi_networkequipments', ['id' => $knownLink->itemId()]);
            if ($existing === null || (int) ($existing['is_deleted'] ?? 0) !== 0) {
                throw new \RuntimeException('TARGET_LINKED_EQUIPMENT_UNAVAILABLE');
            }
        } else {
            $existing = $serial !== '' ? $this->firstUnique('glpi_networkequipments', ['serial' => $serial]) : null;
            if ($existing !== null && (int) ($existing['is_deleted'] ?? 0) !== 0) {
                throw new \RuntimeException('TARGET_EQUIPMENT_DELETED');
            }
            if ($existing !== null && (int) ($existing['locations_id'] ?? 0) > 0 && (int) $existing['locations_id'] !== $siteId) {
                throw new \RuntimeException('SERIAL_ALREADY_ATTACHED_TO_DIFFERENT_SITE');
            }
            $existing ??= $this->firstUnique('glpi_networkequipments', ['name' => $name, 'locations_id' => $siteId, 'is_deleted' => 0]);
        }
        $equipmentData = $this->nonempty([
            'name' => $name,
            'serial' => $serial,
            'locations_id' => $siteId,
            'networkequipmenttypes_id' => (int) ($existing['networkequipmenttypes_id'] ?? 0) > 0 ? (int) $existing['networkequipmenttypes_id'] : $this->resolveDropdown('glpi_networkequipmenttypes', '\\NetworkEquipmentType', $type),
            'networkequipmentmodels_id' => (int) ($existing['networkequipmentmodels_id'] ?? 0) > 0 ? (int) $existing['networkequipmentmodels_id'] : $this->resolveDropdown('glpi_networkequipmentmodels', '\\NetworkEquipmentModel', $model),
            'states_id' => (int) ($existing['states_id'] ?? 0) > 0 ? (int) $existing['states_id'] : $this->resolveDropdown('glpi_states', '\\State', $this->value($row, $map, 'status')),
            'comment' => $this->value($row, $map, 'comment'),
        ]);
        $this->stage = 'equipment_write';
        $item = new \NetworkEquipment();
        if ($existing === null) {
            $id = (int) $item->add($equipmentData + ['entities_id' => 0]);
            if ($id <= 0) {
                throw new \RuntimeException('NETWORK_EQUIPMENT_CREATE_FAILED');
            }
            $action = 'create';
        } else {
            $id = (int) $existing['id'];
            $delta = $this->delta($existing, $equipmentData);
            if ($delta !== [] && !$item->update(['id' => $id] + $delta)) {
                throw new \RuntimeException('NETWORK_EQUIPMENT_UPDATE_FAILED');
            }
            $action = $delta === [] ? 'skip' : 'update';
        }
        $this->targetId = $id;
        $this->rememberLink($job, $sourceId, TargetItemtype::NETWORK_EQUIPMENT, $id, 'glpi_rest_v1_indigo_network_equipment');
        $this->stage = 'equipment_fields';
        $changed = $this->fieldsWriter->write('NetworkEquipment', $id, [
            'Entité Inpark' => $this->value($row, $map, 'entity_code'),
            'Code DA' => $this->value($row, $map, 'code_da'),
            'Code DA Comptable' => $this->value($row, $map, 'code_da_accounting'),
            'Code ETS' => $this->value($row, $map, 'code_ets'),
            'Code DB' => $this->value($row, $map, 'code_db'),
            'Code NA' => $this->value($row, $map, 'code_na'),
            'Code NB' => $this->value($row, $map, 'code_nb'),
            'Date de mise en service' => $this->value($row, $map, 'commissioning_date'),
            'Date ventilation' => $this->value($row, $map, 'ventilation_date'),
            'Date retour Stock' => $this->value($row, $map, 'stock_return_date'),
            'Montant Mensuel' => $this->value($row, $map, 'monthly_amount'),
            'Déjà Amorti' => $this->value($row, $map, 'depreciated'),
            'Montant HT' => $this->value($row, $map, 'amount_ht'),
            'ID source GLPI 10' => $sourceId,
            'Source Last update' => $this->value($row, $map, 'source_updated_at'),
            'Source Creation date' => $this->value($row, $map, 'source_created_at'),
        ]);
        if ($changed && $action === 'skip') {
            $action = 'update';
        }
        $this->stage = 'equipment_financial';
        $rawAmount = $this->value($row, $map, 'amount_ht');
        if ($rawAmount !== '') {
            $amount = $this->normalizeMoney($rawAmount);
            $infocom = $this->firstUnique('glpi_infocoms', ['itemtype' => 'NetworkEquipment', 'items_id' => $id]);
            $obj = new \Infocom();
            $financialChanged = false;
            if ($infocom === null) {
                if (!(int) $obj->add(['itemtype' => 'NetworkEquipment', 'items_id' => $id, 'value' => $amount])) {
                    throw new \RuntimeException('NETWORK_FINANCIAL_CREATE_FAILED');
                }
                $financialChanged = true;
            } elseif ($this->delta($infocom, ['value' => $amount]) !== []) {
                if (!$obj->update(['id' => (int) $infocom['id'], 'value' => $amount])) {
                    throw new \RuntimeException('NETWORK_FINANCIAL_UPDATE_FAILED');
                }
                $financialChanged = true;
            }
            if ($financialChanged && $action === 'skip') {
                $action = 'update';
            }
        }
        return [$action, $id, TargetItemtype::NETWORK_EQUIPMENT, 'glpi_rest_v1_indigo_network_equipment'];
    }

    private function dateOrEmpty(array $row, array $map, string $logical): string
    {
        $value = $this->value($row, $map, $logical);
        return $value === '' ? '' : $this->normalizeDate($value);
    }

    private function coordinate(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $value = str_replace(',', '.', $value);
        if (!is_numeric($value)) {
            throw new \RuntimeException('SOURCE_COORDINATE_INVALID');
        }
        return $value;
    }
}
