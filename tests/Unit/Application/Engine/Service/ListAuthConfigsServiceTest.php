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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Application\Engine\DTO\AuthConfigDTO;
use GlpiPlugin\Uxconnector\Application\Engine\Service\ListAuthConfigsService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListAuthConfigsService::class)]
final class ListAuthConfigsServiceTest extends TestCase
{
   public function testExecuteMapsEveryPersistedAuthConfigToADto(): void {
       $first = AuthConfig::create(
           new AuthConfigName('Microsoft Graph'),
           new Endpoint('https://graph.microsoft.com/v1.0'),
           AuthMethod::OAUTH2_CLIENT_CREDENTIALS
       );
       $first->assignId(new AuthConfigId(1));

       $second = AuthConfig::create(
           new AuthConfigName('Zabbix'),
           new Endpoint('https://zabbix.example.com'),
           AuthMethod::API_KEY
       );
       $second->assignId(new AuthConfigId(2));

       $repository = new class([$first, $second]) implements AuthConfigRepositoryInterface {
         public function __construct(private readonly array $items) {
         }

         public function save(AuthConfig $authConfig): AuthConfig {
             return $authConfig;
         }

         public function findById(AuthConfigId $id): ?AuthConfig {
             return null;
         }

         public function findAll(): array {
             return $this->items;
         }

         public function delete(AuthConfigId $id): void {
         }

         public function exists(AuthConfigId $id): bool {
             return false;
         }
       };

         $service = new ListAuthConfigsService($repository);

         $result = $service->execute();

         self::assertCount(2, $result);
         self::assertContainsOnlyInstancesOf(AuthConfigDTO::class, $result);
         self::assertSame('Microsoft Graph', $result[0]->name);
         self::assertSame('Zabbix', $result[1]->name);
   }

   public function testExecuteReturnsEmptyArrayWhenNoAuthConfigExists(): void {
       $repository = new class implements AuthConfigRepositoryInterface {
         public function save(AuthConfig $authConfig): AuthConfig {
            return $authConfig;
         }

         public function findById(AuthConfigId $id): ?AuthConfig {
             return null;
         }

         public function findAll(): array {
             return [];
         }

         public function delete(AuthConfigId $id): void {
         }

         public function exists(AuthConfigId $id): bool {
             return false;
         }
       };

         $service = new ListAuthConfigsService($repository);

         self::assertSame([], $service->execute());
   }
}
