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

use GlpiPlugin\Uxconnector\Application\Engine\Command\CreateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Service\CreateAuthConfigService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateAuthConfigService::class)]
final class CreateAuthConfigServiceTest extends TestCase
{
   private function makeCommand(?string $plaintextSecret = 'sk-1234'): CreateAuthConfigCommand {
       return new CreateAuthConfigCommand(
           new AuthConfigName('Microsoft Graph'),
           new Endpoint('https://graph.microsoft.com/v1.0'),
           AuthMethod::API_KEY,
           $plaintextSecret
       );
   }

   public function testHappyPathPersistsProtectsAndDispatches(): void {
       $repository = new InMemoryAuthConfigRepository();
       $secretStore = new InMemoryAuthConfigSecretStore();
       $vault = new UppercasingFakeVault();
       $transaction = new RecordingTransactionManager();
       $events = new RecordingEventDispatcher();

       $service = new CreateAuthConfigService($repository, $secretStore, $vault, new PermissiveFakeDestinationPolicy(), $transaction, $events);

       $authConfig = $service->execute($this->makeCommand('sk-1234'));

       self::assertInstanceOf(AuthConfig::class, $authConfig);
       self::assertNotNull($authConfig->id());
       self::assertSame(['begin', 'commit'], $transaction->calls);
       self::assertCount(1, $events->dispatched);
       self::assertSame('SK-1234', $secretStore->get($authConfig->id()));
   }

   public function testNoSecretStoredWhenAuthMethodDoesNotRequireOne(): void {
       $repository = new InMemoryAuthConfigRepository();
       $secretStore = new InMemoryAuthConfigSecretStore();
       $vault = new UppercasingFakeVault();
       $transaction = new RecordingTransactionManager();
       $events = new RecordingEventDispatcher();

       $service = new CreateAuthConfigService($repository, $secretStore, $vault, new PermissiveFakeDestinationPolicy(), $transaction, $events);

       $command = new CreateAuthConfigCommand(
           new AuthConfigName('Public Status Page'),
           new Endpoint('https://status.example.com'),
           AuthMethod::NONE,
           null
       );

       $authConfig = $service->execute($command);

       self::assertNull($secretStore->get($authConfig->id()));
   }

   public function testCredentialedHttpEndpointIsRejectedBeforePersistence(): void {
       $repository = new InMemoryAuthConfigRepository();
       $transaction = new RecordingTransactionManager();

       $service = new CreateAuthConfigService(
           $repository,
           new InMemoryAuthConfigSecretStore(),
           new UppercasingFakeVault(),
           new PermissiveFakeDestinationPolicy(),
           $transaction,
           new RecordingEventDispatcher()
       );

       $command = new CreateAuthConfigCommand(
           new AuthConfigName('Unsafe API'),
           new Endpoint('http://api.internal.local'),
           AuthMethod::API_KEY,
           'secret'
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\InsecureCredentialTransportException::class);

      try {
          $service->execute($command);
      } finally {
          self::assertSame([], $repository->findAll());
          self::assertSame([], $transaction->calls);
      }
   }

   public function testUnauthenticatedHttpEndpointRemainsAllowed(): void {
       $service = new CreateAuthConfigService(
           new InMemoryAuthConfigRepository(),
           new InMemoryAuthConfigSecretStore(),
           new UppercasingFakeVault(),
           new PermissiveFakeDestinationPolicy(),
           new RecordingTransactionManager(),
           new RecordingEventDispatcher()
       );

       $authConfig = $service->execute(new CreateAuthConfigCommand(
           new AuthConfigName('Internal public endpoint'),
           new Endpoint('http://status.internal.local'),
           AuthMethod::NONE,
           null
       ));

       self::assertNotNull($authConfig->id());
   }

   public function testMissingCredentialForMethodThatRequiresOneIsRejected(): void {
       $service = new CreateAuthConfigService(
           new InMemoryAuthConfigRepository(),
           new InMemoryAuthConfigSecretStore(),
           new UppercasingFakeVault(),
           new PermissiveFakeDestinationPolicy(),
           new RecordingTransactionManager(),
           new RecordingEventDispatcher()
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Engine\Exception\MissingCredentialException::class);

       $service->execute($this->makeCommand(null));
   }

    /**
     * CORRECTIF P0 (revue de code externe) : avant ce correctif, rien
     * n'empêchait de créer une AuthConfig en OAuth2, alors que
     * GenericHttpEngine ne peut structurellement pas l'exécuter.
     */
   public function testUnimplementedAuthMethodIsRejectedBeforeAnyPersistence(): void {
       $repository = new InMemoryAuthConfigRepository();
       $transaction = new RecordingTransactionManager();
       $events = new RecordingEventDispatcher();

       $service = new CreateAuthConfigService(
           $repository,
           new InMemoryAuthConfigSecretStore(),
           new UppercasingFakeVault(),
           new PermissiveFakeDestinationPolicy(),
           $transaction,
           $events
       );

       $command = new CreateAuthConfigCommand(
           new AuthConfigName('Legacy interactive SSO'),
           new Endpoint('https://example.com/api'),
           AuthMethod::OAUTH2_AUTHORIZATION_CODE,
           'whatever'
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthMethodNotSupportedException::class);

      try {
          $service->execute($command);
      } finally {
          self::assertSame([], $repository->findAll(), 'no AuthConfig should have been persisted');
          self::assertSame([], $transaction->calls, 'no transaction should have been opened');
          self::assertSame([], $events->dispatched);
      }
   }

    /**
     * CORRECTIF SÉCURITÉ (SSRF, revue de code externe) : la vérification
     * de destination doit intervenir avant toute persistance, comme
     * celle de la méthode d'authentification.
     */
   public function testForbiddenDestinationIsRejectedBeforeAnyPersistence(): void {
       $repository = new InMemoryAuthConfigRepository();
       $transaction = new RecordingTransactionManager();
       $events = new RecordingEventDispatcher();

       $service = new CreateAuthConfigService(
           $repository,
           new InMemoryAuthConfigSecretStore(),
           new UppercasingFakeVault(),
           new BlockingFakeDestinationPolicy(),
           $transaction,
           $events
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::class);

      try {
          $service->execute($this->makeCommand());
      } finally {
          self::assertSame([], $repository->findAll(), 'no AuthConfig should have been persisted');
          self::assertSame([], $transaction->calls, 'no transaction should have been opened');
          self::assertSame([], $events->dispatched);
      }
   }

   public function testRepositoryFailureRollsBackAndNeverDispatches(): void {
       $repository = new class implements AuthConfigRepositoryInterface {
         public function save(AuthConfig $authConfig): AuthConfig {
            throw new \RuntimeException('database is on fire');
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

         $transaction = new RecordingTransactionManager();
         $events = new RecordingEventDispatcher();

         $service = new CreateAuthConfigService(
           $repository,
           new InMemoryAuthConfigSecretStore(),
           new UppercasingFakeVault(),
           new PermissiveFakeDestinationPolicy(),
           $transaction,
           $events
       );

      try {
          $service->execute($this->makeCommand());
          self::fail('Expected exception was not thrown.');
      } catch (\RuntimeException) {
          self::addToAssertionCount(1);
      }

       self::assertSame(['begin', 'rollback'], $transaction->calls);
       self::assertSame([], $events->dispatched);
   }
}

/**
 * @internal test double — always allows, so tests unrelated to SSRF
 * protection don't need to reason about it.
 */
final class PermissiveFakeDestinationPolicy implements DestinationPolicyInterface
{
   public function assertAllowed(\GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint $endpoint): void {
   }
}

/**
 * @internal test double — always blocks, to exercise
 * CreateAuthConfigService's SSRF guard without depending on real DNS
 * resolution.
 */
final class BlockingFakeDestinationPolicy implements DestinationPolicyInterface
{
   public function assertAllowed(\GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint $endpoint): void {
       throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::blockedAddress($endpoint->value(), '127.0.0.1');
   }
}

/**
 * @internal test double — in-memory Repository assigning sequential ids.
 */
final class InMemoryAuthConfigRepository implements AuthConfigRepositoryInterface
{
    /** @var array<int, AuthConfig> */
   private array $storage = [];
   private int $nextId = 1;

   public function save(AuthConfig $authConfig): AuthConfig {
      if ($authConfig->id() === null) {
          $authConfig->assignId(new AuthConfigId($this->nextId++));
      }

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

/**
 * @internal test double
 */
final class InMemoryAuthConfigSecretStore implements AuthConfigSecretStoreInterface
{
    /** @var array<int, string> */
   private array $secrets = [];

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
 * @internal test double — deterministic, non-cryptographic "vault" (uppercase)
 * so tests can assert on the stored value without depending on OpenSslCredentialVault.
 */
final class UppercasingFakeVault implements CredentialVaultInterface
{
   public function protect(string $plaintextSecret): string {
       return strtoupper($plaintextSecret);
   }

   public function reveal(string $protectedSecret): string {
       return strtolower($protectedSecret);
   }
}

/**
 * @internal test double
 */
final class RecordingTransactionManager implements TransactionManagerInterface
{
    /** @var list<string> */
   public array $calls = [];

   public function begin(): void {
       $this->calls[] = 'begin';
   }

   public function commit(): void {
       $this->calls[] = 'commit';
   }

   public function rollback(): void {
       $this->calls[] = 'rollback';
   }
}

/**
 * @internal test double
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<DomainEvent> */
   public array $dispatched = [];

   public function dispatch(DomainEvent $event): void {
       $this->dispatched[] = $event;
   }
}
