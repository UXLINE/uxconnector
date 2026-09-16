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

namespace GlpiPlugin\Uxconnector\Tests\Unit;

use GlpiPlugin\Uxconnector\Setup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Setup::class)]
final class SetupTest extends TestCase
{
   public function testVersionReturnsExpectedMetadata(): void {
       $metadata = Setup::version();

       self::assertSame('UXConnector', $metadata['name']);
       self::assertSame(Setup::VERSION, $metadata['version']);
       self::assertSame('GPL-3.0-or-later', $metadata['license']);
       self::assertArrayHasKey('requirements', $metadata);
       self::assertSame('11.0.0', $metadata['requirements']['glpi']['min']);
       self::assertSame('8.2', $metadata['requirements']['php']['min']);
   }

   public function testCheckPrerequisitesSucceedsOnSupportedPhpVersion(): void {
       self::assertTrue(Setup::checkPrerequisites());
   }

   public function testCheckConfigurationSucceedsWhenNoConfigurationExistsYet(): void {
       self::assertTrue(Setup::checkConfiguration());
       self::assertTrue(Setup::checkConfiguration(true));
   }

   public function testVersionConstantFollowsSemanticVersioning(): void {
       self::assertMatchesRegularExpression(
           '/^\d+\.\d+\.\d+$/',
           Setup::VERSION,
       );
   }
}
