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

use Glpi\Application\View\TemplateRenderer;
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
    public const WEBHOOK_CREDENTIALS = 'plugin_uxconnector_webhook_credentials';

    /**
     * Returns the translated label of the profile tab.
     *
     * @param int $nb Number of items requested by GLPI.
     * @return string Translated tab label.
     */
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
     * Displays the rights tab for a saved profile.
     *
     * @param mixed $item GLPI item hosting the tab.
     * @param int $tabnum Tab selected by GLPI.
     * @param int $withtemplate Template context supplied by GLPI.
     * @return bool True after handling the tab.
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
     * @return list<array{itemtype?: class-string, field: string, label: string, rights?: array<int, string>}>
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
            [
                'field' => self::WEBHOOK_CREDENTIALS,
                'label' => __('Webhook credentials', 'uxconnector'),
                'rights' => [READ => __('Reveal', 'uxconnector'), UPDATE => __('Rotate', 'uxconnector')],
            ],
        ];
    }

    /**
     * Renders the native rights matrix in a Twig form for one GLPI profile.
     *
     * @param int $profilesId ID of the profile whose rights are displayed.
     * @return void
     */
    public static function showForProfile(int $profilesId): void
    {
        Session::checkRight('profile', READ);
        $profile = new Profile();
        if (!$profile->getFromDB($profilesId)) {
            return;
        }

        $canEdit = Session::haveRight('profile', UPDATE);

        TemplateRenderer::getInstance()->display('@uxconnector/profile/rights.html.twig', [
            'profile_id' => $profilesId,
            'profile' => $profile,
            'form_url' => Profile::getFormURL(),
            'can_edit' => $canEdit,
            'rights_definitions' => self::getRightDefinitions(),
            'rights_title' => self::getTypeName(),
        ]);
    }
}
