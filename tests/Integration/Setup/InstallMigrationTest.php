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
 * Nécessite une instance GLPI de test réelle (GLPI_ROOT défini, voir
 * tests/bootstrap.php). Non exécutable dans un environnement sans
 * runtime GLPI — c'est précisément ce que cette suite valide.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Integration\Setup;

use Migration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PluginUxconnectorProfile;

/**
 * Validates Setup::install() end to end against a real GLPI database:
 * every table is created, and — the item flagged as "à valider
 * empiriquement" by the code review — Migration::addRight() actually
 * registers both plugin rights in glpi_profilerights.
 *
 * @covers \GlpiPlugin\Uxconnector\Setup::install
 */
#[Group('integration')]
final class InstallMigrationTest extends TestCase
{
   private const TABLES = [
        'glpi_plugin_uxconnector_authconfigs',
        'glpi_plugin_uxconnector_authconfig_secrets',
        'glpi_plugin_uxconnector_syncjobs',
        'glpi_plugin_uxconnector_syncexecutions',
        'glpi_plugin_uxconnector_objectlinks',
    ];

   protected function setUp(): void {
      if (!defined('GLPI_ROOT')) {
          self::markTestSkipped('Requires a real GLPI instance (GLPI_ROOT).');
      }
   }

   public function testInstallCreatesEveryPluginTable(): void {
       global $DB;

       $migration = new Migration(200);
       $installScript = require dirname(__DIR__, 3) . '/sql/install/install-2.0.0.php';
       $installScript($migration);
       $migration->executeMigration();

      foreach (self::TABLES as $table) {
          self::assertTrue(
              $DB->tableExists($table),
              sprintf('Expected table "%s" to exist after install.', $table)
          );
      }

       self::assertTrue($DB->fieldExists(
           'glpi_plugin_uxconnector_authconfigs',
           'test_path'
       ));

      foreach ([
           'collection_path',
           'pagination_config',
           'template_id',
           'template_version',
       ] as $field) {
          self::assertTrue(
              $DB->fieldExists('glpi_plugin_uxconnector_syncjobs', $field),
              sprintf('Expected SyncJob field "%s" to exist after install.', $field)
          );
      }

       self::assertTrue(isIndex(
           'glpi_plugin_uxconnector_syncjobs',
           'template_id'
       ));
   }

   public function testInstallIsIdempotent(): void {
       global $DB;

       $migration = new Migration(200);
       $installScript = require dirname(__DIR__, 3) . '/sql/install/install-2.0.0.php';

       // RULE-MIGRATION-002 : running install twice must never fail
       // nor duplicate anything.
       $installScript($migration);
       $migration->executeMigration();

       $installScript($migration);
       $migration->executeMigration();

      foreach (self::TABLES as $table) {
          self::assertTrue($DB->tableExists($table));
      }
   }

    /**
     * The exact item the code review flagged as unverified:
     * Migration::addRight() must actually create a usable right — not
     * just run without throwing.
     */

   public function testObjectLinkSchemaIsScopedBySyncJob(): void {
       global $DB;

       $migration = new Migration(251);
       $installScript = require dirname(__DIR__, 3) . '/sql/install/install-2.0.0.php';
       $installScript($migration);
       $migration->executeMigration();

       self::assertTrue($DB->fieldExists(
           'glpi_plugin_uxconnector_objectlinks',
           'plugin_uxconnector_syncjobs_id'
       ));
       self::assertTrue(isIndex(
           'glpi_plugin_uxconnector_objectlinks',
           'uniq_scope_external'
       ));
   }

   public function testInstallRegistersBothPluginRights(): void {
       global $DB;

       $migration = new Migration(200);
       $installScript = require dirname(__DIR__, 3) . '/sql/install/install-2.0.0.php';
       $installScript($migration);
       $migration->executeMigration();

      foreach (PluginUxconnectorProfile::getRightDefinitions() as $definition) {
          $rows = iterator_to_array($DB->request([
              'FROM'  => 'glpi_profilerights',
              'WHERE' => ['name' => $definition['field']],
          ]));

          self::assertNotEmpty(
              $rows,
              sprintf(
                  'Expected at least one glpi_profilerights row for "%s" after install.',
                  $definition['field']
              )
          );
      }
   }
}
