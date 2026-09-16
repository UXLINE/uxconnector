<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\Template;

use GlpiPlugin\Uxconnector\Infrastructure\Template\JsonConnectorTemplateRepository;
use PHPUnit\Framework\TestCase;

final class JsonConnectorTemplateRepositoryTest extends TestCase
{
    public function testBundledCatalogueLoadsAndContainsExpectedStandardInteractions(): void
    {
        $root = dirname(__DIR__, 4);
        $repository = new JsonConnectorTemplateRepository($root . '/resources/connectors');
        $connectors = $repository->findAll();
        $byId = [];
        foreach ($connectors as $connector) {
            $byId[$connector->id] = $connector;
        }

        self::assertArrayHasKey('jira', $byId);
        self::assertArrayHasKey('intune', $byId);
        self::assertArrayHasKey('mdm', $byId);
        self::assertArrayHasKey('zendesk', $byId);
        self::assertNotNull($byId['jira']->findJob('jira.projects.inbound'));
        self::assertNotNull($byId['jira']->findJob('jira.issues.inbound'));
        self::assertNotNull($byId['jira']->findJob('jira.issues.webhook.inbound'));
        self::assertNotNull($byId['jira']->findJob('jira.projects.outbound'));
        self::assertNotNull($byId['jira']->findJob('jira.issues.outbound'));
        self::assertNotNull($byId['intune']->findJob('intune.managed-devices.inbound'));
        self::assertNotNull($byId['glpi-hl']->findJob('glpi-hl.assets.computers.inbound'));
        self::assertNotNull($byId['glpi-hl']->findJob('glpi-hl.assets.monitors.inbound'));
        self::assertNotNull($byId['glpi-hl']->findJob('glpi-hl.assets.phones.inbound'));
        self::assertNotNull($byId['glpi-hl']->findJob('glpi-hl.assets.peripherals.inbound'));
        self::assertNotNull($byId['glpi-hl']->findJob('glpi-hl.assets.printers.inbound'));
        self::assertNotNull($byId['glpi-hl']->findJob('glpi-hl.assets.network-equipment.inbound'));
        self::assertNotNull($byId['zendesk']->findJob('zendesk.tickets.inbound'));
        self::assertNotNull($byId['zendesk']->findJob('zendesk.tickets.active.inbound'));
        self::assertNotNull($byId['zendesk']->findJob('zendesk.tickets.nonclosed.inbound'));
        self::assertNotNull($byId['zendesk']->findJob('zendesk.users.bootstrap'));
        self::assertNotNull($byId['zendesk']->findJob('zendesk.groups.bootstrap'));
        self::assertNotNull($byId['zendesk']->findJob('zendesk.organizations.bootstrap'));
        self::assertNotNull($byId['zendesk']->findJob('zendesk.ticket-forms.bootstrap'));
    }
}
