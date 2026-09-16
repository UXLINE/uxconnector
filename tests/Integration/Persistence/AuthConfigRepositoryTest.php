<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * Nécessite une instance GLPI de test réelle (GLPI_ROOT défini).
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Integration\Persistence;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigPersistenceMapper;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigSecretStore;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Security\OpenSslCredentialVault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigRepository
 */
#[Group('integration')]
#[CoversClass(AuthConfigRepository::class)]
final class AuthConfigRepositoryTest extends TestCase
{
   private AuthConfigRepository $repository;
   private AuthConfigSecretStore $secretStore;

    /** @var list<int> ids created by this test, purged in tearDown() */
   private array $createdIds = [];

   protected function setUp(): void {
      if (!defined('GLPI_ROOT')) {
          self::markTestSkipped('Requires a real GLPI instance (GLPI_ROOT).');
      }

       $this->secretStore = new AuthConfigSecretStore();
       $this->repository = new AuthConfigRepository(
           new AuthConfigPersistenceMapper(),
           $this->secretStore
       );
   }

   public function testSaveThenFindByIdReturnsAnEquivalentAggregate(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Integration Test AuthConfig'),
           new Endpoint('https://api.example.com'),
           AuthMethod::API_KEY
       );

       $saved = $this->repository->save($authConfig);
       $this->createdIds[] = $saved->id()->value();

       $found = $this->repository->findById($saved->id());

       self::assertNotNull($found);
       self::assertSame('Integration Test AuthConfig', $found->name()->value());
       self::assertSame('https://api.example.com', $found->endpoint()->value());
       self::assertSame('/', $found->testPath()->value());
       self::assertSame(AuthMethod::API_KEY, $found->authMethod());
       self::assertSame('draft', $found->status()->value);
   }

   public function testFindByIdReturnsNullForAnUnknownId(): void {
       self::assertNull($this->repository->findById(new AuthConfigId(999_999)));
   }

   public function testUpdateReplacesFieldsOfAnExistingAuthConfig(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Integration Test AuthConfig - before'),
           new Endpoint('https://before.example.com'),
           AuthMethod::NONE
       );
       $saved = $this->repository->save($authConfig);
       $this->createdIds[] = $saved->id()->value();

       $saved->rename(new AuthConfigName('Integration Test AuthConfig - after'));
       $this->repository->save($saved);

       $reloaded = $this->repository->findById($saved->id());

       self::assertSame('Integration Test AuthConfig - after', $reloaded->name()->value());
   }

   public function testExistsReflectsActualDatabaseState(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Integration Test AuthConfig - exists'),
           new Endpoint('https://api.example.com'),
           AuthMethod::NONE
       );
       $saved = $this->repository->save($authConfig);
       $this->createdIds[] = $saved->id()->value();

       self::assertTrue($this->repository->exists($saved->id()));
       self::assertFalse($this->repository->exists(new AuthConfigId(999_999)));
   }

   public function testDeleteCascadesToTheProtectedSecret(): void {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Integration Test AuthConfig - with secret'),
           new Endpoint('https://api.example.com'),
           AuthMethod::API_KEY
       );
       $saved = $this->repository->save($authConfig);

       $vault = new OpenSslCredentialVault();
       $this->secretStore->put($saved->id(), $vault->protect('sk-integration-test'));

       self::assertNotNull($this->secretStore->get($saved->id()));

       $this->repository->delete($saved->id());

       self::assertNull($this->repository->findById($saved->id()));
       self::assertNull(
           $this->secretStore->get($saved->id()),
           'The protected secret must be purged along with its AuthConfig.'
       );
   }

   protected function tearDown(): void {
      foreach ($this->createdIds as $id) {
          $this->repository->delete(new AuthConfigId($id));
      }

       $this->createdIds = [];
   }
}
