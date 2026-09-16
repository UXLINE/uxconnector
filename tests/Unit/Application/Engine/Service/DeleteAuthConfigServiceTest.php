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

use GlpiPlugin\Uxconnector\Application\Engine\Command\DeleteAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Service\DeleteAuthConfigService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigUsageCheckerInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\TestCase;

final class DeleteAuthConfigServiceTest extends TestCase
{
   public function testDeletesUnusedAuthConfigInsideTransaction(): void {
       $repository = new DeleteAuthConfigRepositoryDouble([$this->makeAuthConfig()]);
       $usageChecker = new DeleteAuthConfigUsageCheckerDouble(false);
       $transaction = new DeleteAuthConfigTransactionDouble();
       $service = new DeleteAuthConfigService($repository, $usageChecker, $transaction);

       $service->execute(new DeleteAuthConfigCommand(new AuthConfigId(1)));

       self::assertFalse($repository->exists(new AuthConfigId(1)));
       self::assertSame(1, $transaction->begins);
       self::assertSame(1, $transaction->commits);
       self::assertSame(0, $transaction->rollbacks);
   }

   public function testRejectsAuthConfigReferencedBySyncJob(): void {
       $repository = new DeleteAuthConfigRepositoryDouble([$this->makeAuthConfig()]);
       $service = new DeleteAuthConfigService(
           $repository,
           new DeleteAuthConfigUsageCheckerDouble(true),
           new DeleteAuthConfigTransactionDouble()
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigInUseException::class);
       $service->execute(new DeleteAuthConfigCommand(new AuthConfigId(1)));
   }

   public function testRejectsUnknownAuthConfig(): void {
       $service = new DeleteAuthConfigService(
           new DeleteAuthConfigRepositoryDouble([]),
           new DeleteAuthConfigUsageCheckerDouble(false),
           new DeleteAuthConfigTransactionDouble()
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::class);
       $service->execute(new DeleteAuthConfigCommand(new AuthConfigId(999)));
   }

   private function makeAuthConfig(): AuthConfig {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Delete me'),
           new Endpoint('https://api.example.test'),
           AuthMethod::NONE
       );
       $authConfig->assignId(new AuthConfigId(1));

       return $authConfig;
   }
}

final class DeleteAuthConfigRepositoryDouble implements AuthConfigRepositoryInterface
{
    /** @var array<int, AuthConfig> */
   private array $items = [];

    /** @param list<AuthConfig> $items */
   public function __construct(array $items) {
      foreach ($items as $item) {
          $this->items[$item->id()->value()] = $item;
      }
   }

   public function save(AuthConfig $authConfig): AuthConfig {
       $this->items[$authConfig->id()->value()] = $authConfig;
       return $authConfig;
   }

   public function findById(AuthConfigId $id): ?AuthConfig {
       return $this->items[$id->value()] ?? null;
   }

   public function findAll(): array {
       return array_values($this->items);
   }

   public function delete(AuthConfigId $id): void {
       unset($this->items[$id->value()]);
   }

   public function exists(AuthConfigId $id): bool {
       return isset($this->items[$id->value()]);
   }
}

final class DeleteAuthConfigUsageCheckerDouble implements AuthConfigUsageCheckerInterface
{
   public function __construct(private readonly bool $used) {
   }

   public function isUsed(AuthConfigId $id): bool {
       return $this->used;
   }
}

final class DeleteAuthConfigTransactionDouble implements TransactionManagerInterface
{
   public int $begins = 0;
   public int $commits = 0;
   public int $rollbacks = 0;

   public function begin(): void {
       ++$this->begins;
   }

   public function commit(): void {
       ++$this->commits;
   }

   public function rollback(): void {
       ++$this->rollbacks;
   }
}
