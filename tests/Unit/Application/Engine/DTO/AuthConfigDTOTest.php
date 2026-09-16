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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Engine\DTO;

use GlpiPlugin\Uxconnector\Application\Engine\DTO\AuthConfigDTO;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthConfigDTO::class)]
final class AuthConfigDTOTest extends TestCase
{
   public function testFromEntityMapsEveryField(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Microsoft Graph'),
           new Endpoint('https://graph.microsoft.com/v1.0'),
           AuthMethod::OAUTH2_CLIENT_CREDENTIALS
       );
       $authConfig->assignId(new AuthConfigId(5));
       $authConfig->markTesting();
       $authConfig->activate();

       $dto = AuthConfigDTO::fromEntity($authConfig);

       self::assertSame(5, $dto->id);
       self::assertSame('Microsoft Graph', $dto->name);
       self::assertSame('https://graph.microsoft.com/v1.0', $dto->endpoint);
       self::assertSame('/', $dto->testPath);
       self::assertSame('oauth2_client_credentials', $dto->authMethod);
       self::assertSame(AuthMethod::OAUTH2_CLIENT_CREDENTIALS->label(), $dto->authMethodLabel);
       self::assertSame('active', $dto->status);
       self::assertTrue($dto->isUsable);
   }

   public function testFromEntityRejectsAggregateWithoutId(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           new Endpoint('https://zabbix.example.com'),
           AuthMethod::API_KEY
       );

       $this->expectException(\LogicException::class);

       AuthConfigDTO::fromEntity($authConfig);
   }
}
