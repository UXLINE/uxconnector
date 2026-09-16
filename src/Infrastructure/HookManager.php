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

namespace GlpiPlugin\Uxconnector\Infrastructure;

use Glpi\Http\Firewall;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigItem;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigSecretItem;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\ObjectLinkItem;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncExecutionItem;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobItem;
use Plugin;

/**
 * Centralizes every declaration UXConnector makes to the GLPI core at
 * plugin_init_uxconnector() time: registered classes and the main
 * navigation menu today, rights / CronTasks / notifications / REST
 * endpoints as later commits introduce them.
 *
 * RULE-HOOKMANAGER-001/002/004 : Setup::init() delegates immediately
 * and entirely to register(); no other method in this class is
 * public, and each private method stays under ~40 lines and covers a
 * single kind of declaration (RULE-HOOKMANAGER-004/005).
 */
final class HookManager
{
    /**
     * Registers every hook/class declaration used by the plugin.
     */
   public static function register(): void {
       self::registerPluginClasses();
       self::registerMenus();
       self::registerProfileRights();
       self::registerSecuredFields();
       self::registerMachineEndpoints();
       self::registerItemEvents();
   }

    /**
     * Declares the CommonDBTM-derived classes owned by the Engine and
     * Sync bounded contexts so GLPI's core (rights, autoload cache,
     * uninstall) is aware of them.
     *
     * None of these classes is attached to another GLPI itemtype's
     * tabs ('addtabon' => []): UXConnector exposes its own dedicated
     * screens (front/authconfig.php, front/syncjob.php) reachable
     * from registerMenus() below, rather than piggy-backing on
     * another object's tab bar.
     */
   private static function registerPluginClasses(): void {
       Plugin::registerClass(AuthConfigItem::class, [
           'addtabon' => [],
       ]);

       Plugin::registerClass(AuthConfigSecretItem::class, [
           'addtabon' => [],
       ]);

       Plugin::registerClass(SyncJobItem::class, [
           'addtabon' => [],
       ]);

       Plugin::registerClass(SyncExecutionItem::class, [
           'addtabon' => [],
       ]);

       Plugin::registerClass(ObjectLinkItem::class, [
           'addtabon' => [],
       ]);
   }

    /**
     * Declares the plugin's entry in GLPI's main navigation.
     *
     * REX empirique (voir inc/menu.class.php) : contrairement aux
     * classes CommonDBTM métier, la classe de menu doit être une
     * classe legacy sans namespace ('PluginUxconnectorMenu'), et sa
     * déclaration dans $PLUGIN_HOOKS['menu_toadd'] doit utiliser le
     * nom de classe directement sous forme de chaîne — jamais
     * '::class' sur une classe namespacée, jamais de FQCN.
     */
   private static function registerMenus(): void {
       global $PLUGIN_HOOKS;

       $PLUGIN_HOOKS['menu_toadd']['uxconnector'] = [
           'tools' => 'PluginUxconnectorMenu',
       ];
   }

    /**
     * Attaches the plugin's rights matrix to GLPI's own Profile edit
     * screen — same REX-driven legacy convention as registerMenus()
     * (see inc/profile.class.php's doc-block).
     */
   private static function registerProfileRights(): void {
       Plugin::registerClass('PluginUxconnectorProfile', [
           'addtabon' => ['Profile'],
       ]);
   }
    /**
     * Registers the encrypted AuthConfig secret field with GLPI so the core
     * security:change_key command rotates UXConnector credentials together
     * with GLPI's own encrypted values.
     */
   private static function registerSecuredFields(): void {
       global $PLUGIN_HOOKS;

       $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['uxconnector'] = [
           'glpi_plugin_uxconnector_authconfig_secrets.protected_secret',
       ];
   }


    /** Public endpoint is authenticated by the interaction's 256-bit webhook key. */
   private static function registerMachineEndpoints(): void {
       Firewall::addPluginStrategyForLegacyScripts(
           'uxconnector',
           '#^/front/(webhook|copilot|copilot-openapi|copilot-search|copilot-context|copilot-capabilities|copilot-ticket-summary|copilot-ticket-trends|copilot-categories|copilot-sla|copilot-sla-breaches|copilot-groups|copilot-technicians|copilot-compare|copilot-incidentology-global|copilot-incidentology-groups|copilot-incidentology-typologies|copilot-semantic-classification|copilot-semantic-prepare|copilot-semantic-finalize)\.php$#',
           Firewall::STRATEGY_NO_CHECK
       );
   }

    /** GLPI changes that may drive active outbound interactions. */
   private static function registerItemEvents(): void {
       global $PLUGIN_HOOKS;

       $callbacks = [
           'Project' => 'plugin_uxconnector_item_changed',
           'Ticket' => 'plugin_uxconnector_item_changed',
           'Computer' => 'plugin_uxconnector_item_changed',
           'Monitor' => 'plugin_uxconnector_item_changed',
           'NetworkEquipment' => 'plugin_uxconnector_item_changed',
           'Peripheral' => 'plugin_uxconnector_item_changed',
           'Phone' => 'plugin_uxconnector_item_changed',
           'Printer' => 'plugin_uxconnector_item_changed',
           'User' => 'plugin_uxconnector_item_changed',
       ];

       $PLUGIN_HOOKS['item_add']['uxconnector'] = $callbacks;
       $PLUGIN_HOOKS['item_update']['uxconnector'] = $callbacks;
   }

}
