<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\Template;

use GlpiPlugin\Uxconnector\Infrastructure\Template\TemplateServiceProvider;
use PHPUnit\Framework\TestCase;

final class TemplateServiceProviderTest extends TestCase
{
    public function testBundledConnectorDirectoryIsResolvedFromPluginRoot(): void
    {
        $connectors = TemplateServiceProvider::repository()->findAll();

        self::assertNotEmpty($connectors);
        $ids = array_map(static fn ($connector): string => $connector->id, $connectors);
        self::assertContains('jira', $ids);
        self::assertContains('intune', $ids);
        self::assertContains('rest', $ids);
        self::assertContains('zendesk', $ids);
    }
}
