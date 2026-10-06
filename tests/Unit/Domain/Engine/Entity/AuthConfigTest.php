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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Engine\Entity;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthConfigStatus;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Event\AuthConfigCreatedEvent;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthConfig::class)]
final class AuthConfigTest extends TestCase
{
   private function makeEndpoint(): Endpoint {
       return new Endpoint('https://graph.microsoft.com/v1.0');
   }

   public function testCreateStartsInDraftStatusWithoutIdentifier(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Microsoft Graph'),
           $this->makeEndpoint(),
           AuthMethod::OAUTH2_CLIENT_CREDENTIALS
       );

       self::assertNull($authConfig->id());
       self::assertSame(AuthConfigStatus::DRAFT, $authConfig->status());
       self::assertFalse($authConfig->isUsable());
   }

   public function testCreateRecordsAuthConfigCreatedEvent(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $events = $authConfig->releaseEvents();

       self::assertCount(1, $events);
       self::assertInstanceOf(AuthConfigCreatedEvent::class, $events[0]);
       self::assertSame('engine.auth_config.created', $events[0]->name());
   }

   public function testReleaseEventsClearsTheInternalBuffer(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $authConfig->releaseEvents();

       self::assertSame([], $authConfig->releaseEvents());
   }

   public function testAssignIdSetsTheIdentifierOnce(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $authConfig->assignId(new AuthConfigId(1));

       self::assertSame(1, $authConfig->id()?->value());
   }

   public function testAssignIdCannotBeCalledTwice(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $authConfig->assignId(new AuthConfigId(1));

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\InvalidAuthConfigStateException::class);

       $authConfig->assignId(new AuthConfigId(2));
   }

   public function testActivateFailsWhenStatusIsNotTesting(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\InvalidAuthConfigStateException::class);

       $authConfig->activate();
   }

   public function testFullLifecycleFromDraftToActive(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $authConfig->markTesting();
       self::assertSame(AuthConfigStatus::TESTING, $authConfig->status());

       $authConfig->activate();
       self::assertSame(AuthConfigStatus::ACTIVE, $authConfig->status());
       self::assertTrue($authConfig->isUsable());

       $authConfig->disable();
       self::assertSame(AuthConfigStatus::DISABLED, $authConfig->status());
       self::assertFalse($authConfig->isUsable());
   }

   public function testChangingEndpointResetsStatusToDraft(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $authConfig->markTesting();
       $authConfig->activate();

       $authConfig->changeEndpoint(new Endpoint('https://zabbix.helpline.fr/api'));

       self::assertSame(AuthConfigStatus::DRAFT, $authConfig->status());
   }

   public function testChangingAuthMethodResetsStatusToDraft(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $authConfig->markTesting();
       $authConfig->activate();

       $authConfig->changeAuthMethod(AuthMethod::BEARER_TOKEN);

       self::assertSame(AuthConfigStatus::DRAFT, $authConfig->status());
       self::assertSame(AuthMethod::BEARER_TOKEN, $authConfig->authMethod());
   }

   public function testMarkErrorMovesStatusToError(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $authConfig->markTesting();
       $authConfig->markError();

       self::assertSame(AuthConfigStatus::ERROR, $authConfig->status());
   }

   public function testRenameUpdatesNameAndTouchesUpdatedAt(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           $this->makeEndpoint(),
           AuthMethod::API_KEY
       );

       $originalUpdatedAt = $authConfig->updatedAt();
       usleep(1_000);

       $authConfig->rename(new AuthConfigName('Zabbix - Site Nantes'));

       self::assertSame('Zabbix - Site Nantes', $authConfig->name()->value());
       self::assertGreaterThan($originalUpdatedAt, $authConfig->updatedAt());
   }

   public function testReconstituteDoesNotRecordAnyEvent(): void {
       $authConfig = AuthConfig::reconstitute(
           new AuthConfigId(10),
           new AuthConfigName('Jira'),
           $this->makeEndpoint(),
           AuthMethod::OAUTH2_AUTHORIZATION_CODE,
           AuthConfigStatus::ACTIVE,
           new DateTimeImmutable('2026-01-01'),
           new DateTimeImmutable('2026-01-02')
       );

       self::assertSame([], $authConfig->releaseEvents());
       self::assertSame(10, $authConfig->id()?->value());
       self::assertSame(AuthConfigStatus::ACTIVE, $authConfig->status());
   }
   public function testChangingTestPathResetsStatusToDraft(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Jira'),
           new Endpoint('https://api.atlassian.com/ex/jira/cloud-id'),
           AuthMethod::BEARER_TOKEN,
           new TestPath('/')
       );

       $authConfig->markTesting();
       $authConfig->activate();
       $authConfig->changeTestPath(new TestPath('/rest/api/3/project/search?maxResults=1'));

       self::assertSame(AuthConfigStatus::DRAFT, $authConfig->status());
       self::assertSame('/rest/api/3/project/search?maxResults=1', $authConfig->testPath()->value());
   }

   public function testChangingConnectorTypeResetsStatusToDraft(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Legacy Jira'),
           new Endpoint('https://api.atlassian.com/ex/jira/cloud-id'),
           AuthMethod::BEARER_TOKEN,
           new TestPath('/'),
           'custom'
       );

       $authConfig->markTesting();
       $authConfig->activate();
       $authConfig->changeConnectorId('jira');

       self::assertSame(AuthConfigStatus::DRAFT, $authConfig->status());
       self::assertSame('jira', $authConfig->connectorId());
   }

}
