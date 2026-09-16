<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Application\Engine\Command\UpdateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Service\UpdateAuthConfigService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthConfigStatus;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\TestCase;

final class UpdateAuthConfigServiceTest extends TestCase
{
   private function makeActiveConnection(): AuthConfig {
       $connection = AuthConfig::create(
           new AuthConfigName('Jira'),
           new Endpoint('https://api.atlassian.com/ex/jira/cloud-id'),
           AuthMethod::BEARER_TOKEN,
           new TestPath('/rest/api/3/project/search?maxResults=1'),
           'jira'
       );
       $connection->assignId(new AuthConfigId(1));
       $connection->markTesting();
       $connection->activate();
       return $connection;
   }

   public function testBlankCredentialKeepsExistingSecretAndActiveStateWhenOnlyNameChanges(): void {
       $repo = new UpdateTestRepo($this->makeActiveConnection());
       $secrets = new UpdateTestSecretStore('PROTECTED-OLD');
       $tx = new UpdateTestTx();
       $service = new UpdateAuthConfigService($repo, $secrets, new UpdateTestVault(), new UpdateTestDestination(), $tx);

       $updated = $service->execute(new UpdateAuthConfigCommand(
           new AuthConfigId(1),
           new AuthConfigName('Jira renamed'),
           new Endpoint('https://api.atlassian.com/ex/jira/cloud-id'),
           AuthMethod::BEARER_TOKEN,
           new TestPath('/rest/api/3/project/search?maxResults=1'),
           null
       ));

       self::assertSame('Jira renamed', $updated->name()->value());
       self::assertSame(AuthConfigStatus::ACTIVE, $updated->status());
       self::assertSame('PROTECTED-OLD', $secrets->get(new AuthConfigId(1)));
       self::assertSame(['begin', 'commit'], $tx->calls);
   }

   public function testChangingTestPathRequiresRetest(): void {
       $repo = new UpdateTestRepo($this->makeActiveConnection());
       $service = new UpdateAuthConfigService(
           $repo,
           new UpdateTestSecretStore('PROTECTED-OLD'),
           new UpdateTestVault(),
           new UpdateTestDestination(),
           new UpdateTestTx()
       );

       $updated = $service->execute(new UpdateAuthConfigCommand(
           new AuthConfigId(1),
           new AuthConfigName('Jira'),
           new Endpoint('https://api.atlassian.com/ex/jira/cloud-id'),
           AuthMethod::BEARER_TOKEN,
           new TestPath('/rest/api/3/myself'),
           null
       ));

       self::assertSame(AuthConfigStatus::DRAFT, $updated->status());
       self::assertSame('/rest/api/3/myself', $updated->testPath()->value());
   }

   public function testReplacingCredentialRequiresRetestAndReplacesProtectedSecret(): void {
       $repo = new UpdateTestRepo($this->makeActiveConnection());
       $secrets = new UpdateTestSecretStore('PROTECTED-OLD');
       $service = new UpdateAuthConfigService($repo, $secrets, new UpdateTestVault(), new UpdateTestDestination(), new UpdateTestTx());

       $updated = $service->execute(new UpdateAuthConfigCommand(
           new AuthConfigId(1),
           new AuthConfigName('Jira'),
           new Endpoint('https://api.atlassian.com/ex/jira/cloud-id'),
           AuthMethod::BEARER_TOKEN,
           new TestPath('/rest/api/3/project/search?maxResults=1'),
           'new-token'
       ));

       self::assertSame(AuthConfigStatus::DRAFT, $updated->status());
       self::assertSame('PROTECTED:new-token', $secrets->get(new AuthConfigId(1)));
   }

   public function testChangingCredentialedAuthMethodRequiresNewCredential(): void {
       $repo = new UpdateTestRepo($this->makeActiveConnection());
       $service = new UpdateAuthConfigService(
           $repo,
           new UpdateTestSecretStore('PROTECTED-OLD'),
           new UpdateTestVault(),
           new UpdateTestDestination(),
           new UpdateTestTx()
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Engine\Exception\MissingCredentialException::class);

       $service->execute(new UpdateAuthConfigCommand(
           new AuthConfigId(1),
           new AuthConfigName('Jira'),
           new Endpoint('https://api.atlassian.com/ex/jira/cloud-id'),
           AuthMethod::BASIC,
           new TestPath('/rest/api/3/project/search?maxResults=1'),
           null
       ));
   }
}

final class UpdateTestRepo implements AuthConfigRepositoryInterface
{
   public function __construct(private AuthConfig $item) {}
   public function save(AuthConfig $authConfig): AuthConfig {
      $this->item = $authConfig; return $authConfig; }
   public function findById(AuthConfigId $id): ?AuthConfig {
      return $id->value() === 1 ? $this->item : null; }
   public function findAll(): array {
      return [$this->item]; }
   public function delete(AuthConfigId $id): void {}
   public function exists(AuthConfigId $id): bool {
      return $id->value() === 1; }
}

final class UpdateTestSecretStore implements AuthConfigSecretStoreInterface
{
   private ?string $value;
   public function __construct(?string $value) {
      $this->value = $value; }
   public function put(AuthConfigId $id, string $protectedSecret): void {
      $this->value = $protectedSecret; }
   public function get(AuthConfigId $id): ?string {
      return $this->value; }
   public function delete(AuthConfigId $id): void {
      $this->value = null; }
}

final class UpdateTestVault implements CredentialVaultInterface
{
   public function protect(string $plaintextSecret): string {
      return 'PROTECTED:' . $plaintextSecret; }
   public function reveal(string $protectedSecret): string {
      return $protectedSecret; }
}

final class UpdateTestDestination implements DestinationPolicyInterface
{
   public function assertAllowed(Endpoint $endpoint): void {}
}

final class UpdateTestTx implements TransactionManagerInterface
{
    /** @var list<string> */
   public array $calls = [];
   public function begin(): void {
      $this->calls[] = 'begin'; }
   public function commit(): void {
      $this->calls[] = 'commit'; }
   public function rollback(): void {
      $this->calls[] = 'rollback'; }
}
