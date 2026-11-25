<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use GlpiPlugin\Uxconnector\Infrastructure\Sync\Console\ReviewActiveJobScopesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ReviewActiveJobScopesCommandTest extends TestCase
{
    public function testNativeLoaderRequiresAnActivatedPlugin(): void
    {
        if (!defined('NS_PLUG')) {
            define('NS_PLUG', 'GlpiPlugin\\');
        }
        $plugin = new class () extends \Plugin {
            public static string $fixtureDirectory;

            public static function getPhpDir(string $plugin_key = '', $full = true): string
            {
                return self::$fixtureDirectory;
            }
        };
        $plugin::$fixtureDirectory = dirname(__DIR__, 2);
        // Use GLPI's real getPlugins() and command scanner. No runtime database is loaded.
        $active = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $original = $active->getValue();
        $originalConfig = $GLOBALS['CFG_GLPI'] ?? null;
        try {
            $GLOBALS['CFG_GLPI'] = [];
            foreach ([[], ['uxconnector']] as $plugins) {
                $active->setValue(null, $plugins);
                $loader = new \Glpi\Console\CommandLoader(true, dirname(__DIR__, 2), $plugin);
                (new \ReflectionMethod($loader, 'findPluginCommands'))->invoke($loader);
                $commands = (new \ReflectionProperty($loader, 'commands'))->getValue($loader) ?? [];
                self::assertSame(
                    $plugins !== [],
                    isset($commands['plugins:uxconnector:review-active-job-scopes']),
                );
            }
        } finally {
            $active->setValue(null, $original);
            if ($originalConfig === null) {
                unset($GLOBALS['CFG_GLPI']);
            } else {
                $GLOBALS['CFG_GLPI'] = $originalConfig;
            }
        }
    }

    public function testNativeCommandNameAndReadOnlyJsonOutput(): void
    {
        $db = $this->getMockBuilder(\DBmysql::class)->disableOriginalConstructor()->onlyMethods(['request'])->getMock();
        $db->expects(self::once())->method('request')->willReturn([]);
        $command = new ReviewActiveJobScopesCommand();
        self::assertSame('plugins:uxconnector:review-active-job-scopes', $command->getName());
        (new \ReflectionProperty($command, 'db'))->setValue($command, $db);
        $output = new BufferedOutput();
        $code = (new \ReflectionMethod($command, 'execute'))->invoke($command, new ArrayInput([]), $output);
        self::assertSame(0, $code);
        self::assertSame([], json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR)['active_jobs']);
    }

    public function testCommandFailsWithoutDatabase(): void
    {
        $command = new ReviewActiveJobScopesCommand();
        $output = new BufferedOutput();
        $code = (new \ReflectionMethod($command, 'execute'))->invoke($command, new ArrayInput([]), $output);
        self::assertSame(1, $code);
        self::assertStringContainsString('database connection is required', $output->fetch());
    }
}
