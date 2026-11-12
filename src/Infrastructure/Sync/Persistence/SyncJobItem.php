<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * RULE-032/AI-ENTITY-002 : purement technique, jamais utilisée par le
 * Domaine ou l'Application. Seul SyncJobRepository la manipule.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use CommonDBTM;

/**
 * GLPI Active Record model backing the `glpi_plugin_uxconnector_syncjobs`
 * table.
 */
final class SyncJobItem extends CommonDBTM
{
    // Must stay in sync with PluginUxconnectorProfile::getRightDefinitions()
    // (inc/profile.class.php) - see AuthConfigItem::$rightname's comment
    // for why this cannot be derived automatically.
    public static $rightname = 'plugin_uxconnector_syncjob';

    /** Keep credentials out of GLPI API representations. */
    public static $undisclosedFields = [
        'webhook_key', 'webhook_key_hash', 'webhook_token_secret', 'webhook_signing_secret',
    ];

    /** Job mutations must use the plugin services that check both current and requested entity scopes. */
    public function can($ID, int $right, ?array &$input = null): bool
    {
        if (in_array($right, [CREATE, UPDATE, DELETE, PURGE], true)) {
            return false;
        }

        return parent::can($ID, $right, $input);
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_uxconnector_syncjobs';
    }

    public static function getTypeName($nb = 0): string
    {
        return \_n('Synchronization job', 'Synchronization jobs', $nb, 'uxconnector');
    }

    public function getName($options = []): string
    {
        return $this->fields['name'] ?? '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rawSearchOptions(): array
    {
        $options = parent::rawSearchOptions();

        $options[] = [
            'id'       => '2',
            'table'    => $this->getTable(),
            'field'    => 'direction',
            'name'     => \__('Direction', 'uxconnector'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '5',
            'table'    => $this->getTable(),
            'field'    => 'resource_path',
            'name'     => \__('Resource path', 'uxconnector'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '6',
            'table'    => $this->getTable(),
            'field'    => 'target_itemtype',
            'name'     => \__('Target itemtype', 'uxconnector'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '3',
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => \__('Status', 'uxconnector'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'            => '19',
            'table'         => $this->getTable(),
            'field'         => 'date_mod',
            'name'          => \__('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $options[] = [
            'id'            => '121',
            'table'         => $this->getTable(),
            'field'         => 'date_creation',
            'name'          => \__('Creation date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        return $options;
    }
}
