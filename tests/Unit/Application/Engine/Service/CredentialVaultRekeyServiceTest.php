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

use GlpiPlugin\Uxconnector\Application\Engine\Service\CredentialVaultRekeyService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CredentialVaultRekeyService::class)]
final class CredentialVaultRekeyServiceTest extends TestCase
{
   private function makeAuthConfig(int $id, AuthMethod $method = AuthMethod::API_KEY): AuthConfig {
       $authConfig = AuthConfig::create(
           new AuthConfigName("AuthConfig $id"),
           new Endpoint('https://api.example.com'),
           $method
       );
       $authConfig->assignId(new AuthConfigId($id));

       return $authConfig;
   }

   public function testRotatesEverySecretFromOldVaultToNewVault(): void {
       $a = $this->makeAuthConfig(1);
       $b = $this->makeAuthConfig(2);

       $repository = new InMemoryAuthConfigRepo([$a, $b]);
       $secretStore = new InMemorySecretStore([
           1 => 'old-vault:sk-aaa',
           2 => 'old-vault:sk-bbb',
       ]);

       $service = new CredentialVaultRekeyService($repository, $secretStore);

       $result = $service->execute(new FakeVault('old-vault:'), new FakeVault('new-vault:'));

       self::assertSame(2, $result->rotated);
       self::assertSame(0, $result->skipped);
       self::assertSame(0, $result->failed);
       self::assertTrue($result->isFullySuccessful());
       self::assertSame('new-vault:sk-aaa', $secretStore->get(new AuthConfigId(1)));
       self::assertSame('new-vault:sk-bbb', $secretStore->get(new AuthConfigId(2)));
   }

   public function testSkipsAuthConfigsWithNoStoredSecret(): void {
       $noneAuth = $this->makeAuthConfig(1, AuthMethod::NONE);

       $repository = new InMemoryAuthConfigRepo([$noneAuth]);
       $secretStore = new InMemorySecretStore([]); // nothing stored for AuthMethod::NONE

       $service = new CredentialVaultRekeyService($repository, $secretStore);

       $result = $service->execute(new FakeVault('old-vault:'), new FakeVault('new-vault:'));

       self::assertSame(0, $result->rotated);
       self::assertSame(1, $result->skipped);
       self::assertSame(0, $result->failed);
   }

    /**
     * CORRECTIF SÉCURITÉ : un secret qui échoue à tourner (ex. déjà
     * migré par une exécution partielle précédente) ne doit jamais
     * interrompre la rotation des autres — même principe d'isolation
     * que ExecuteSyncJobService::applyFieldMappings().
     */
   public function testOneFailingSecretDoesNotAbortTheRestOfTheBatch(): void {
       $a = $this->makeAuthConfig(1);
       $b = $this->makeAuthConfig(2);
       $c = $this->makeAuthConfig(3);

       $repository = new InMemoryAuthConfigRepo([$a, $b, $c]);
       $secretStore = new InMemorySecretStore([
           1 => 'old-vault:sk-aaa',
           2 => 'already-migrated:sk-bbb', // wrong prefix -> oldVault->reveal() will throw
           3 => 'old-vault:sk-ccc',
       ]);

       $service = new CredentialVaultRekeyService($repository, $secretStore);

       $result = $service->execute(new FakeVault('old-vault:'), new FakeVault('new-vault:'));

       self::assertSame(2, $result->rotated);
       self::assertSame(1, $result->failed);
       self::assertFalse($result->isFullySuccessful());
       self::assertArrayHasKey(2, $result->errors);
       self::assertSame('new-vault:sk-aaa', $secretStore->get(new AuthConfigId(1)));
       self::assertSame('new-vault:sk-ccc', $secretStore->get(new AuthConfigId(3)));
       // The failed one is left untouched, not corrupted:
       self::assertSame('already-migrated:sk-bbb', $secretStore->get(new AuthConfigId(2)));
   }

   public function testEmptyRepositoryYieldsAZeroedResult(): void {
       $service = new CredentialVaultRekeyService(
           new InMemoryAuthConfigRepo([]),
           new InMemorySecretStore([])
       );

       $result = $service->execute(new FakeVault('old:'), new FakeVault('new:'));

       self::assertSame(0, $result->rotated);
       self::assertSame(0, $result->skipped);
       self::assertSame(0, $result->failed);
       self::assertTrue($result->isFullySuccessful());
   }
}

/**
 * @internal test double — read-only in-memory Repository (rekey never
 * calls save()).
 */
final class InMemoryAuthConfigRepo implements AuthConfigRepositoryInterface
{
   public function __construct(
        private readonly array $items
    ) {
   }

   public function save(AuthConfig $authConfig): AuthConfig {
       throw new \RuntimeException('CredentialVaultRekeyService must never call save().');
   }

   public function findById(AuthConfigId $id): ?AuthConfig {
      foreach ($this->items as $item) {
         if ($item->id()?->equals($id)) {
            return $item;
         }
      }

       return null;
   }

   public function findAll(): array {
       return $this->items;
   }

   public function delete(AuthConfigId $id): void {
   }

   public function exists(AuthConfigId $id): bool {
       return $this->findById($id) !== null;
   }
}

/**
 * @internal test double
 */
final class InMemorySecretStore implements AuthConfigSecretStoreInterface
{
    /** @var array<int, string> */
   private array $secrets;

   public function __construct(array $secrets) {
       $this->secrets = $secrets;
   }

   public function put(AuthConfigId $id, string $protectedSecret): void {
       $this->secrets[$id->value()] = $protectedSecret;
   }

   public function get(AuthConfigId $id): ?string {
       return $this->secrets[$id->value()] ?? null;
   }

   public function delete(AuthConfigId $id): void {
       unset($this->secrets[$id->value()]);
   }
}

/**
 * @internal test double — a deterministic, prefix-based "vault": each
 * instance only reveal()s secrets protected with its own prefix,
 * throwing otherwise. This simulates OpenSslCredentialVault refusing
 * to decrypt a payload protected with a different key.
 */
final class FakeVault implements CredentialVaultInterface
{
   public function __construct(
        private readonly string $prefix
    ) {
   }

   public function protect(string $plaintextSecret): string {
       return $this->prefix . $plaintextSecret;
   }

   public function reveal(string $protectedSecret): string {
      if (!str_starts_with($protectedSecret, $this->prefix)) {
          throw new \RuntimeException('simulated decryption failure: wrong key');
      }

       return substr($protectedSecret, strlen($this->prefix));
   }
}
