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

use GlpiPlugin\Uxconnector\Application\Engine\Command\ActivateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\DisableAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Service\ActivateAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\DisableAuthConfigService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthConfigStatus;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivateAuthConfigService::class)]
#[CoversClass(DisableAuthConfigService::class)]
final class ActivateDisableAuthConfigServiceTest extends TestCase
{
   private function makeAuthConfig(AuthConfigStatus $status): AuthConfig {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Microsoft Graph'),
           new Endpoint('https://graph.microsoft.com/v1.0'),
           AuthMethod::NONE
       );
       $authConfig->assignId(new AuthConfigId(1));

      if ($status === AuthConfigStatus::TESTING) {
          $authConfig->markTesting();
      } else if ($status === AuthConfigStatus::ACTIVE) {
          $authConfig->markTesting();
          $authConfig->activate();
      } else if ($status === AuthConfigStatus::ERROR) {
          $authConfig->markError();
      }

       return $authConfig;
   }

   public function testActivateSucceedsFromTesting(): void {
       $repository = new InMemoryAuthConfigRepoForLifecycle([$this->makeAuthConfig(AuthConfigStatus::TESTING)]);
       $service = new ActivateAuthConfigService($repository);

       $authConfig = $service->execute(new ActivateAuthConfigCommand(new AuthConfigId(1)));

       self::assertSame(AuthConfigStatus::ACTIVE, $authConfig->status());
   }

   public function testActivateFailsFromDraft(): void {
       $repository = new InMemoryAuthConfigRepoForLifecycle([$this->makeAuthConfig(AuthConfigStatus::DRAFT)]);
       $service = new ActivateAuthConfigService($repository);

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\InvalidAuthConfigStateException::class);

       $service->execute(new ActivateAuthConfigCommand(new AuthConfigId(1)));
   }

   public function testActivateRejectsUnknownAuthConfig(): void {
       $service = new ActivateAuthConfigService(new InMemoryAuthConfigRepoForLifecycle([]));

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::class);

       $service->execute(new ActivateAuthConfigCommand(new AuthConfigId(999)));
   }

   public function testDisableWorksFromActive(): void {
       $repository = new InMemoryAuthConfigRepoForLifecycle([$this->makeAuthConfig(AuthConfigStatus::ACTIVE)]);
       $service = new DisableAuthConfigService($repository);

       $authConfig = $service->execute(new DisableAuthConfigCommand(new AuthConfigId(1)));

       self::assertSame(AuthConfigStatus::DISABLED, $authConfig->status());
   }

   public function testDisableWorksFromError(): void {
       $repository = new InMemoryAuthConfigRepoForLifecycle([$this->makeAuthConfig(AuthConfigStatus::ERROR)]);
       $service = new DisableAuthConfigService($repository);

       $authConfig = $service->execute(new DisableAuthConfigCommand(new AuthConfigId(1)));

       self::assertSame(AuthConfigStatus::DISABLED, $authConfig->status());
   }

   public function testDisableRejectsUnknownAuthConfig(): void {
       $service = new DisableAuthConfigService(new InMemoryAuthConfigRepoForLifecycle([]));

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::class);

       $service->execute(new DisableAuthConfigCommand(new AuthConfigId(999)));
   }
}

/**
 * @internal test double
 */
final class InMemoryAuthConfigRepoForLifecycle implements \GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface
{
    /** @var array<int, AuthConfig> */
   private array $storage = [];

   public function __construct(array $items) {
      foreach ($items as $item) {
          $this->storage[$item->id()->value()] = $item;
      }
   }

   public function save(AuthConfig $authConfig): AuthConfig {
       $this->storage[$authConfig->id()->value()] = $authConfig;

       return $authConfig;
   }

   public function findById(AuthConfigId $id): ?AuthConfig {
       return $this->storage[$id->value()] ?? null;
   }

   public function findAll(): array {
       return array_values($this->storage);
   }

   public function delete(AuthConfigId $id): void {
       unset($this->storage[$id->value()]);
   }

   public function exists(AuthConfigId $id): bool {
       return isset($this->storage[$id->value()]);
   }
}
