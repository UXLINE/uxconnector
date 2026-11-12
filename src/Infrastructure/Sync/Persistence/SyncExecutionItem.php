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
 * Domaine ou l'Application. Seul SyncExecutionRepository la manipule.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use CommonDBTM;

/**
 * GLPI Active Record model backing the
 * `glpi_plugin_uxconnector_syncexecutions` table.
 *
 * Deliberately separate from SyncJobItem (see SyncExecution's class
 * doc-block for the rationale): this table is expected to grow much
 * faster than interactions. A future retention CronTask may purge old
 * rows independently; deleting an interaction explicitly also purges
 * its execution history to avoid orphaned supervision data.
 */
final class SyncExecutionItem extends CommonDBTM
{
    // See AuthConfigItem::$rightname's comment: must stay in sync
    // with PluginUxconnectorProfile::getRightDefinitions().
    public static $rightname = 'plugin_uxconnector_syncjob';

    /** Execution states are changed through the plugin, including requests to queue a job. */
    public function can($ID, int $right, ?array &$input = null): bool
    {
        if (in_array($right, [CREATE, UPDATE, DELETE, PURGE], true)) {
            return false;
        }

        return parent::can($ID, $right, $input);
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_uxconnector_syncexecutions';
    }

    public static function getTypeName($nb = 0): string
    {
        return \_n('Synchronization execution', 'Synchronization executions', $nb, 'uxconnector');
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
            'field'    => 'status',
            'name'     => \__('Status', 'uxconnector'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '3',
            'table'    => $this->getTable(),
            'field'    => 'started_at',
            'name'     => \__('Started at', 'uxconnector'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => '4',
            'table'    => $this->getTable(),
            'field'    => 'finished_at',
            'name'     => \__('Finished at', 'uxconnector'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => '5',
            'table'    => $this->getTable(),
            'field'    => 'items_processed',
            'name'     => \__('Items processed', 'uxconnector'),
            'datatype' => 'number',
        ];

        $options[] = [
            'id'       => '6',
            'table'    => $this->getTable(),
            'field'    => 'items_failed',
            'name'     => \__('Items failed', 'uxconnector'),
            'datatype' => 'number',
        ];

        return $options;
    }
}
