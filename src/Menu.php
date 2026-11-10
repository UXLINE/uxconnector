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

namespace GlpiPlugin\Uxconnector;

use CommonDBTM;
use Session;

/**
 * GLPI navigation entry point for UXConnector.
 *
 * $notable = true is required by GLPI's menu_toadd mechanism even
 * though this class is never actually used to store or list records
 * - it only provides getMenuName()/getMenuContent().
 */
class Menu extends CommonDBTM
{
    public static $notable = true;

    public static function getMenuName(): string
    {
        return __('UXConnector', 'uxconnector');
    }

    public static function getMenuIcon(): string
    {
        return 'ti ti-plug-connected';
    }

    /**
     * Builds the navigation entry, conditioned on the visitor
     * actually holding at least one of the plugin's rights.
     *
     * RULE-CTRL-004 (adapted to menus) : hiding an entry is not a
     * security boundary by itself - front/authconfig.php and
     * front/syncjob.php each re-check Session::checkRight()
     * independently. This method only avoids showing a dead-end link
     * to a visitor with no access at all.
     *
     * @return array<string, mixed>|false
     */
    public static function getMenuContent(): array|false
    {
        $canReadConnection = Session::haveRight('plugin_uxconnector_authconfig', READ);
        $canCreateConnection = Session::haveRight('plugin_uxconnector_authconfig', CREATE);
        $canReadInteraction = Session::haveRight('plugin_uxconnector_syncjob', READ);
        $canCreateInteraction = Session::haveRight('plugin_uxconnector_syncjob', CREATE);
        $canReadCopilot = Session::haveRight('plugin_uxconnector_authconfig', READ);

        if (!$canReadConnection && !$canReadInteraction && !$canReadCopilot) {
            return false;
        }

        $menu = [
            'title' => self::getMenuName(),
            'page'  => $canReadConnection
                ? '/plugins/uxconnector/front/connections.php'
                : '/plugins/uxconnector/front/syncjob.php',
            'icon'  => self::getMenuIcon(),
        ];

        if ($canReadConnection) {
            $menu['options']['connections'] = [
                'title' => __('Connections', 'uxconnector'),
                'page'  => '/plugins/uxconnector/front/connections.php',
                'icon'  => 'ti ti-plug-connected',
                'links' => ['search' => '/plugins/uxconnector/front/connections.php'],
            ];
            if ($canCreateConnection) {
                $menu['options']['connections']['links']['add'] = '/plugins/uxconnector/front/connection.form.php';
            }
        }

        if ($canReadCopilot) {
            $menu['options']['copilot_agents'] = [
                'title' => __('AI Agents', 'uxconnector'),
                'page'  => '/plugins/uxconnector/front/copilot-agents.php',
                'icon'  => 'ti ti-robot',
                'links' => ['search' => '/plugins/uxconnector/front/copilot-agents.php'],
            ];
        }

        if ($canReadInteraction) {
            $menu['options']['catalog'] = [
                'title' => __('Connector catalog', 'uxconnector'),
                'page'  => '/plugins/uxconnector/front/catalog.php',
                'icon'  => 'ti ti-layout-grid',
                'links' => ['search' => '/plugins/uxconnector/front/catalog.php'],
            ];
            $menu['options']['license_assignments'] = [
                'title' => __('Microsoft licence assignments', 'uxconnector'),
                'page' => '/plugins/uxconnector/front/license-assignments.php',
                'icon' => 'ti ti-license',
                'links' => ['search' => '/plugins/uxconnector/front/license-assignments.php'],
            ];
            $menu['options']['interactions'] = [
                'title' => __('Interactions', 'uxconnector'),
                'page'  => '/plugins/uxconnector/front/syncjob.php',
                'icon'  => 'ti ti-arrows-exchange',
                'links' => ['search' => '/plugins/uxconnector/front/syncjob.php'],
            ];
            if ($canCreateInteraction) {
                $menu['options']['advanced'] = [
                    'title' => __('Advanced REST interaction', 'uxconnector'),
                    'page'  => '/plugins/uxconnector/front/syncjob.form.php',
                    'icon'  => 'ti ti-code',
                    'links' => ['add' => '/plugins/uxconnector/front/syncjob.form.php'],
                ];
            }
        }

        return $menu;
    }
}
