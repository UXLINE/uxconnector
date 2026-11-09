<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;

/** One native licence per source assignment; old pooled records remain intact. */
final class IndigoMicrosoftLicenseBatchProcessor extends AbstractLegacyLocationBatchProcessor
{
    private const EXTERNAL_TYPE = 'glpi_rest_v1_indigo_microsoft_license';

    protected function profile(): string
    {
        return LegacyLocationMigrationProfiles::MICROSOFT;
    }

    protected function processRecord(SyncJob $job, array $row, array $map, string $sourceId): array
    {
        $this->stage = 'source_validation';
        $product = $this->value($row, $map, 'product');
        if ($product === '') {
            return ['skip', 0, TargetItemtype::SOFTWARE_LICENSE, self::EXTERNAL_TYPE];
        }
        $legacy = $this->objectLinks->findByExternalId($job->authConfigReference(), 'glpi_rest_v1_indigo_microsoft_pool', new ExternalId($sourceId));
        $oldAssignment = $this->db->tableExists(LicenseAssignmentItem::getTable())
            ? $this->firstUnique(LicenseAssignmentItem::getTable(), ['source_authconfig_id' => $job->authConfigReference()->value(), 'source_location_id' => $sourceId])
            : null;
        if ($legacy !== null || $oldAssignment !== null) {
            // Changing an existing purchased pool into individual native rows
            // without a reviewed conversion could double-count entitlement.
            throw new \RuntimeException('MICROSOFT_LEGACY_POOL_REVIEW_REQUIRED');
        }
        $beneficiary = $this->value($row, $map, 'beneficiary');
        if ($beneficiary === '') {
            throw new \RuntimeException('MICROSOFT_BENEFICIARY_MISSING');
        }
        $fields = [
            'Licences' => $product,
            'Utilisateur' => $beneficiary,
            'Matricule' => $this->value($row, $map, 'registration_number'),
            'Fonction' => $this->value($row, $map, 'function'),
            'Statut' => $this->value($row, $map, 'status'),
            "Date d’activation" => $this->value($row, $map, 'activated_at'),
            'Date de désactivation' => $this->value($row, $map, 'deactivated_at'),
            'Prix annuel' => $this->moneyOrEmpty($this->value($row, $map, 'annual_price')),
            'Prix mensuel' => $this->moneyOrEmpty($this->value($row, $map, 'monthly_price')),
            'Entité' => $this->value($row, $map, 'entity_code'),
            'Code DA' => $this->value($row, $map, 'code_da'),
            'Code DA Comptable' => $this->value($row, $map, 'code_da_accounting'),
            'Code ETS' => $this->value($row, $map, 'code_ets'),
            'Code DB' => $this->value($row, $map, 'code_db'),
            'Code NA' => $this->value($row, $map, 'code_na'),
            'Code NB' => $this->value($row, $map, 'code_nb'),
            'Chemin hiérarchique source' => $this->value($row, $map, 'full_name'),
            'ID lieu source GLPI 10' => $sourceId,
            'Lieu parent historique' => $this->value($row, $map, 'source_parent'),
            'Dernière modification source' => $this->value($row, $map, 'source_updated_at'),
            'Date création source' => $this->value($row, $map, 'source_created_at'),
        ];
        foreach (["Date d’activation", 'Date de désactivation'] as $label) {
            if ($fields[$label] !== '') {
                $fields[$label] = $this->normalizeDate($fields[$label]);
            }
        }
        $this->stage = 'software_resolution';
        $software = $this->firstUnique('glpi_softwares', ['name' => $product, 'entities_id' => 0, 'is_deleted' => 0]);
        if ($software === null) {
            $softwareId = (int) (new \Software())->add(['name' => $product, 'entities_id' => 0]);
            if ($softwareId <= 0) {
                throw new \RuntimeException('TARGET_SOFTWARE_CREATE_FAILED');
            }
        } else {
            $softwareId = (int) $software['id'];
        }

        $this->stage = 'license_resolution';
        $link = $this->objectLinks->findByExternalId($job->authConfigReference(), self::EXTERNAL_TYPE, new ExternalId($sourceId));
        $existing = null;
        if ($link !== null) {
            if ($link->itemtype() !== TargetItemtype::SOFTWARE_LICENSE) {
                throw new \RuntimeException('TARGET_LINK_TYPE_CONFLICT');
            }
            $existing = $this->firstUnique('glpi_softwarelicenses', ['id' => $link->itemId()]);
            if ($existing === null || (int) ($existing['is_deleted'] ?? 0) !== 0) {
                throw new \RuntimeException('TARGET_LINKED_LICENSE_UNAVAILABLE');
            }
        }
        $item = new \SoftwareLicense();
        if ($existing === null) {
            $this->stage = 'license_create';
            $id = (int) $item->add([
                'name' => $product . ' / ' . $beneficiary . ' [source ' . $sourceId . ']',
                'softwares_id' => $softwareId,
                'entities_id' => 0,
                // An assigned unit per row, not an inferred purchased pool.
                'number' => 1,
            ]);
            if ($id <= 0) {
                throw new \RuntimeException('TARGET_LICENSE_CREATE_FAILED');
            }
            $this->rememberLink($job, $sourceId, TargetItemtype::SOFTWARE_LICENSE, $id, self::EXTERNAL_TYPE);
            $action = 'create';
        } else {
            $id = (int) $existing['id'];
            $action = 'skip';
        }
        $this->targetId = $id;
        $this->stage = 'license_fields';
        if ($this->fieldsWriter->write('SoftwareLicense', $id, $fields) && $action === 'skip') {
            $action = 'update';
        }
        // Keep source names as text. Never infer users_id from ambiguous names
        // or non-unique employee numbers. Consolidated LDAP links stay intact.
        return [$action, $id, TargetItemtype::SOFTWARE_LICENSE, self::EXTERNAL_TYPE];
    }

    private function moneyOrEmpty(string $value): string
    {
        return $value === '' ? '' : $this->normalizeMoney($value);
    }
}
