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
 * Nécessite une instance GLPI de test réelle (GLPI_ROOT défini).
 *
 * Validates the GLPI 11 profile-right integration used by UXConnector.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Integration\Presentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PluginUxconnectorProfile;
use Profile;
use ProfileRight;

#[Group('integration')]
final class ProfileRightsTest extends TestCase
{
   protected function setUp(): void {
      if (!defined('GLPI_ROOT')) {
          self::markTestSkipped('Requires a real GLPI instance (GLPI_ROOT).');
      }
   }

    /**
     * GLPI 11 exposes profile rights through ProfileRight::getProfileRights().
     */
   public function testProfileRightApiReturnsAnArray(): void {
       $rights = ProfileRight::getProfileRights(0, ['plugin_uxconnector_authconfig']);

       self::assertIsArray($rights);
   }

    /**
     * PluginUxconnectorProfile::showForProfile() must not fatal and
     * must render every right defined by getRightDefinitions().
     */
   public function testShowForProfileRendersEveryRightDefinition(): void {
       ob_start();
       PluginUxconnectorProfile::showForProfile(0);
       $output = ob_get_clean();

      foreach (PluginUxconnectorProfile::getRightDefinitions() as $definition) {
          self::assertStringContainsString(
              $definition['label'],
              $output,
              sprintf('Expected the rights matrix to render the "%s" label.', $definition['field'])
          );
      }
   }

    /**
     * getTabNameForItem() must not fatal when called the way GLPI's
     * core actually calls it (a real Profile instance) — the exact
     * scenario the RULE-INHERITANCE fix in the code review addressed.
     */
   public function testGetTabNameForItemDoesNotFatalOnARealProfileInstance(): void {
       $profile = new Profile();

       $tabName = (new PluginUxconnectorProfile())->getTabNameForItem($profile);

       self::assertIsString($tabName);
   }
}
