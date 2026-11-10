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

use Html;
use Profile;
use Session;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigItem;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobItem;

/**
 * Declares and renders UXConnector rights on GLPI's Profile screen.
 *
 * GLPI 11 stores plugin rights in glpi_profilerights. Loading a Profile
 * automatically merges these values into $profile->fields through
 * Profile::post_getFromDB(). The native displayRightsChoiceMatrix() helper
 * must be used so the generated form fields follow the _<rightname> naming
 * convention understood by Profile::prepareInputForUpdate().
 */
class ProfileRights extends Profile
{
    public static function getTypeName($nb = 0): string
    {
        return __('UXConnector', 'uxconnector');
    }

    /**
     * Adds an UXConnector tab to GLPI's Profile edit screen.
     *
     * @param mixed $item
     * @param int $withtemplate
     * @return string
     */
    public function getTabNameForItem($item, $withtemplate = 0)
    {
        if (
            !($item instanceof Profile)
             || !$item->getField('id')
             || !Session::haveRight('profile', READ)
        ) {
            return '';
        }

        return self::createTabEntry(self::getTypeName());
    }

    /**
     * @param mixed $item
     * @param int $tabnum
     * @param int $withtemplate
     */
    public static function displayTabContentForItem($item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('id')) {
            self::showForProfile((int) $item->getField('id'));
        }

        return true;
    }

    /**
     * Single source of truth for UXConnector rights.
     *
     * `itemtype` lets GLPI derive the standard READ/UPDATE/CREATE/DELETE/PURGE
     * labels from the corresponding CommonDBTM class. `field` remains the
     * profileright key used by Session checks and by Migration::addRight().
     *
     * @return list<array{itemtype: class-string<CommonDBTM>, field: string, label: string}>
     */
    public static function getRightDefinitions(): array
    {
        return [
            [
                'itemtype' => AuthConfigItem::class,
                'field'    => 'plugin_uxconnector_authconfig',
                'label'    => __('Authentication configurations', 'uxconnector'),
            ],
            [
                'itemtype' => SyncJobItem::class,
                'field'    => 'plugin_uxconnector_syncjob',
                'label'    => __('Synchronization jobs', 'uxconnector'),
            ],
        ];
    }

    /**
     * Renders a GLPI-native editable rights matrix for one profile.
     */
    public static function showForProfile(int $profilesId): void
    {
        $profile = new Profile();
        if (!$profile->getFromDB($profilesId)) {
            return;
        }

        $canEdit = Session::haveRight('profile', UPDATE);

        echo "<div class='firstbloc'>";
        echo "<form name='form' method='post' action='" . htmlescape(Profile::getFormURL()) . "'>";
        echo Html::hidden('id', ['value' => $profilesId]);

        $profile->displayRightsChoiceMatrix(
            self::getRightDefinitions(),
            [
                'title'   => self::getTypeName(),
                'canedit' => $canEdit,
            ],
        );

        if ($canEdit) {
            echo "<div class='center mt-3'>";
            echo Html::submit(
                _x('button', 'Save'),
                [
                    'name'  => 'update',
                    'class' => 'btn btn-primary',
                    'icon'  => 'ti ti-device-floppy',
                ],
            );
            echo '</div>';
        }

        Html::closeForm();
        echo '</div>';
    }
}
