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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Policy;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;

/**
 * Security boundary for generic field mappings.
 *
 * A generic connector must never accept an arbitrary GLPI column name as a
 * writable target. The allow-lists below intentionally exclude framework and
 * security-sensitive columns (id, entities_id, is_deleted, is_template,
 * authentication/token fields, date_mod/date_creation, ...). Entity placement
 * is exclusively controlled by EntityAssignmentStrategy.
 *
 * The lists are aligned with the GLPI 11 core schema. Adding a new writable
 * field is an explicit product decision and must be covered by tests.
 */
final class TargetFieldPolicy
{
    /**
     * @param list<FieldMapping> $mappings
     */
    public function assertConfiguration(
        TargetItemtype $itemtype,
        MatchField $matchField,
        array $mappings
    ): void {
        if (!in_array($matchField->value(), $this->allowedMatchFields($itemtype), true)) {
            throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\UnsafeTargetFieldException::notMatchable($itemtype, $matchField->value());
        }

        $allowed = $this->allowedWritableFields($itemtype);

        foreach ($mappings as $mapping) {
            if (!in_array($mapping->targetField(), $allowed, true) && !self::isFieldsTarget($mapping->targetField())) {
                throw \GlpiPlugin\Uxconnector\Domain\Sync\Exception\UnsafeTargetFieldException::notWritable($itemtype, $mapping->targetField());
            }
        }
    }

    /**
     * Fields target keys are only syntactically approved here. The live
     * Fields catalog and the generated container are verified by the writer
     * before any native GLPI item is modified. Never use an arbitrary SQL
     * column name from a connection's payload.
     */
    public static function isFieldsTarget(string $field): bool
    {
        return preg_match('/^fields:[^\x00-\x1F\x7F]{1,120}$/u', $field) === 1
            && trim(substr($field, 7)) !== '';
    }

    /**
     * @return list<string>
     */
    public function allowedMatchFields(TargetItemtype $itemtype): array
    {
        return match ($itemtype) {
            TargetItemtype::USER => ['name', 'registration_number', 'nickname'],
            TargetItemtype::GROUP, TargetItemtype::ENTITY, TargetItemtype::ITIL_CATEGORY => ['name'],
            TargetItemtype::ITIL_FOLLOWUP => ['items_id'],
            TargetItemtype::DOCUMENT => ['name'],
            TargetItemtype::PROJECT => ['code', 'name'],
            TargetItemtype::TICKET => ['externalid', 'name'],
            TargetItemtype::LOCATION => ['code', 'name'],
            TargetItemtype::LINE => ['caller_num', 'name'],
            TargetItemtype::SOFTWARE_LICENSE => ['serial', 'otherserial', 'name'],
            TargetItemtype::SIM_CARD => ['serial'],
            default => ['asset_identity', 'serial', 'otherserial', 'name', 'uuid'],
        };
    }

    /**
     * @return list<string>
     */
    public function allowedWritableFields(TargetItemtype $itemtype): array
    {
        return match ($itemtype) {
            TargetItemtype::COMPUTER => [
                'name', 'serial', 'otherserial', 'contact', 'contact_num', 'comment',
                'locations_id', 'networks_id', 'computermodels_id', 'computertypes_id',
                'manufacturers_id', 'states_id', 'uuid', 'last_boot',
            ],
            TargetItemtype::MONITOR => [
                'name', 'contact', 'contact_num', 'comment', 'serial', 'otherserial', 'size',
                'have_micro', 'have_speaker', 'have_subd', 'have_bnc', 'have_dvi', 'have_pivot',
                'have_hdmi', 'have_displayport', 'locations_id', 'monitortypes_id',
                'monitormodels_id', 'manufacturers_id', 'states_id', 'uuid',
            ],
            TargetItemtype::NETWORK_EQUIPMENT => [
                'name', 'ram', 'serial', 'otherserial', 'contact', 'contact_num', 'comment',
                'locations_id', 'networks_id', 'networkequipmenttypes_id',
                'networkequipmentmodels_id', 'manufacturers_id', 'states_id', 'uuid',
                'sysdescr', 'cpu', 'uptime',
            ],
            TargetItemtype::PERIPHERAL => [
                'name', 'contact', 'contact_num', 'comment', 'serial', 'otherserial',
                'locations_id', 'peripheraltypes_id', 'peripheralmodels_id', 'brand',
                'manufacturers_id', 'states_id', 'uuid',
            ],
            TargetItemtype::SIM_CARD => [
                'serial', 'otherserial', 'puk', 'puk2', 'pin', 'pin2', 'msin',
                'locations_id', 'lines_id', 'devicesimcards_id', 'itemtype',
                'items_id', 'states_id', 'users_id',
            ],
            TargetItemtype::PHONE => [
                'name', 'contact', 'contact_num', 'comment', 'serial', 'otherserial',
                'locations_id', 'phonetypes_id', 'phonemodels_id', 'brand',
                'phonepowersupplies_id', 'number_line', 'have_headset', 'have_hp',
                'manufacturers_id', 'states_id', 'uuid',
            ],
            TargetItemtype::PRINTER => [
                'name', 'contact', 'contact_num', 'serial', 'otherserial', 'have_serial',
                'have_parallel', 'have_usb', 'have_wifi', 'have_ethernet', 'comment',
                'memory_size', 'locations_id', 'networks_id', 'printertypes_id',
                'printermodels_id', 'manufacturers_id', 'states_id', 'init_pages_counter',
                'last_pages_counter', 'uuid', 'sysdescr',
            ],
            TargetItemtype::USER => [
                'name', 'phone', 'phone2', 'mobile', 'realname', 'firstname', 'locations_id',
                'language', 'comment', 'usertitles_id', 'usercategories_id',
                'registration_number', 'nickname', 'timezone', 'is_active', 'email',
            ],
            TargetItemtype::GROUP => [
                'name', 'comment', 'is_recursive',
            ],
            TargetItemtype::ENTITY => [
                'name', 'comment',
            ],
            TargetItemtype::ITIL_CATEGORY => [
                'name', 'comment', 'is_helpdeskvisible', 'is_incident', 'is_request', 'itilcategories_id',
            ],
            TargetItemtype::ITIL_FOLLOWUP => [
                'items_id', 'itemtype', 'users_id', 'content', 'is_private', 'date',
            ],
            TargetItemtype::DOCUMENT => [
                'name', 'comment', 'documentcategories_id',
            ],
            TargetItemtype::PROJECT => [
                'name', 'code', 'content', 'priority', 'percent_done',
                'plan_start_date', 'plan_end_date', 'real_start_date', 'real_end_date',
                'projectstates_id', 'projecttypes_id', 'locations_id',
            ],
            TargetItemtype::TICKET => [
                'name', 'content', 'externalid', 'type', 'status', 'urgency', 'impact',
                'priority', 'itilcategories_id', 'requesttypes_id', 'locations_id',
                'date', 'time_to_resolve',
            ],
            TargetItemtype::LOCATION => [
                'name', 'code', 'address', 'postcode', 'town', 'latitude', 'longitude', 'comment', 'locations_id',
            ],
            TargetItemtype::LINE => [
                'name', 'caller_num', 'caller_name', 'comment', 'locations_id', 'states_id', 'lineoperators_id', 'linetypes_id',
            ],
            TargetItemtype::SOFTWARE_LICENSE => [
                'name', 'serial', 'otherserial', 'softwares_id', 'softwarelicenses_id',
                'number', 'softwarelicensetypes_id', 'softwareversions_id_buy',
                'softwareversions_id_use', 'expire', 'comment', 'states_id',
                'allow_overquota', 'is_recursive',
            ],
        };
    }
}
