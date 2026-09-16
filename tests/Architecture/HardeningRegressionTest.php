<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class HardeningRegressionTest extends TestCase
{
   private string $root;

   protected function setUp(): void {
       $this->root = dirname(__DIR__, 2);
   }

   public function testComposerPsr4PrefixMatchesRuntimeNamespaceExactly(): void {
       $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

       self::assertSame('src/', $composer['autoload']['psr-4']['GlpiPlugin\\Uxconnector\\'] ?? null);
       self::assertArrayNotHasKey('GlpiPlugin\\UxConnector\\', $composer['autoload']['psr-4']);
   }

   public function testLegacyFrontScriptsDoNotBootstrapGlpiManually(): void {
      foreach ($this->phpFilesUnder('front') as $file) {
          $source = (string) file_get_contents($file->getPathname());
          self::assertStringNotContainsString('inc/includes.php', $source, $file->getPathname());
      }
   }

   public function testRuntimeCodeContainsNoRawSqlExecution(): void {
      foreach (['src', 'front', 'hook.php', 'setup.php'] as $relative) {
         foreach ($this->phpFilesUnder($relative) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/->\s*(?:doQuery|query)\s*\(/', $source, $file->getPathname());
         }
      }
   }

   public function testFreshSchemaUsesGlpi11KeyAndTimestampConventions(): void {
       $source = (string) file_get_contents($this->root . '/sql/install/install-2.0.0.php');

       self::assertDoesNotMatchRegularExpression('/\bDATETIME\b/i', $source);
       self::assertDoesNotMatchRegularExpression('/`(?:id|[a-z0-9_]+_id)`\s+int(?!\s+unsigned)/i', $source);
   }

   public function testObjectLinkIsScopedBySyncJob(): void {
       $source = (string) file_get_contents($this->root . '/sql/install/install-2.0.0.php');

       self::assertStringContainsString('`plugin_uxconnector_syncjobs_id` int unsigned', $source);
       self::assertStringContainsString('`uniq_scope_external`', $source);
   }

    /**
     * @return list<SplFileInfo>
     */
   private function phpFilesUnder(string $relative): array {
       $path = $this->root . '/' . $relative;

      if (is_file($path)) {
          return str_ends_with($path, '.php') ? [new SplFileInfo($path)] : [];
      }

       $files = [];
       $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

      foreach ($iterator as $file) {
         if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
             $files[] = $file;
         }
      }

       return $files;
   }
}
