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
 * Domaine ou l'Application. Seul ObjectLinkRepository la manipule.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use CommonDBTM;

/**
 * GLPI Active Record model backing the
 * `glpi_plugin_uxconnector_objectlinks` table.
 *
 * Uses the same right as SyncJob (`plugin_uxconnector_syncjob`):
 * ObjectLink is purely an internal bookkeeping record produced by
 * running a SyncJob, never a first-class object an administrator
 * manages directly - it has no dedicated screen, only the right that
 * already gates the synchronizations that produce it.
 */
final class ObjectLinkItem extends CommonDBTM
{
    public static $rightname = 'plugin_uxconnector_syncjob';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_uxconnector_objectlinks';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Object link', 'Object links', $nb, 'uxconnector');
    }

    public function rawSearchOptions(): array
    {
        return [];
    }
}
