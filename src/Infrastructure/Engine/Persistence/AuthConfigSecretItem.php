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
 * Domaine ou l'Application. Seul AuthConfigSecretStore la manipule.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence;

use CommonDBTM;

/**
 * GLPI Active Record model backing the
 * `glpi_plugin_uxconnector_authconfig_secrets` table.
 *
 * Deliberately separate from AuthConfigItem: this table only ever
 * contains the opaque payload produced by CredentialVaultInterface,
 * never business attributes, so it can receive stricter access
 * controls / auditing independently of the rest of an AuthConfig.
 */
final class AuthConfigSecretItem extends CommonDBTM
{
    // See AuthConfigItem::$rightname's comment: must stay in sync
    // with PluginUxconnectorProfile::getRightDefinitions().
    public static $rightname = 'plugin_uxconnector_authconfig';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_uxconnector_authconfig_secrets';
    }

    public static function getTypeName($nb = 0): string
    {
        return \_n('Authentication secret', 'Authentication secrets', $nb, 'uxconnector');
    }

    /**
     * Never expose the protected payload through the generic search
     * engine or list views: this table is not meant to be browsable.
     */
    public function rawSearchOptions(): array
    {
        return [];
    }
}
