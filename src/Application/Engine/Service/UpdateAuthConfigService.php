<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Application\Engine\Command\UpdateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;

/**
 * Edits a connection without ever exposing its existing plaintext secret.
 *
 * Any transport/authentication/test-resource/credential change invalidates the
 * previous successful test, therefore the aggregate returns to DRAFT and must
 * be tested again before it can become ACTIVE.
 */
final class UpdateAuthConfigService
{
   public function __construct(
        private readonly AuthConfigRepositoryInterface $repository,
        private readonly AuthConfigSecretStoreInterface $secretStore,
        private readonly CredentialVaultInterface $vault,
        private readonly DestinationPolicyInterface $destinationPolicy,
        private readonly TransactionManagerInterface $transaction
    ) {
   }

   public function execute(UpdateAuthConfigCommand $command): AuthConfig {
       $connection = $this->repository->findById($command->id);
      if ($connection === null) {
          throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::forId($command->id);
      }

      if (!$command->authMethod->isImplemented()) {
          throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthMethodNotSupportedException::forMethod($command->authMethod);
      }

      if ($command->authMethod->requiresVaultedCredentials() && !$command->endpoint->isSecure()) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\InsecureCredentialTransportException::forEndpoint($command->authMethod, $command->endpoint);
      }

       $this->destinationPolicy->assertAllowed($command->endpoint);

       $authMethodChanged = $connection->authMethod() !== $command->authMethod;
       $existingProtectedSecret = $this->secretStore->get($command->id);
       $hasNewSecret = $command->authMethod->requiresVaultedCredentials()
           && $command->plaintextSecret !== null
           && trim($command->plaintextSecret) !== '';

      if ($command->authMethod->requiresVaultedCredentials()) {
         if ($authMethodChanged && !$hasNewSecret) {
             // Re-using an opaque secret after changing its semantic format
             // (Bearer -> Basic, API key -> OAuth2, ...) is unsafe.
             throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\MissingCredentialException::forAuthMethod($command->authMethod);
         }
         if (!$hasNewSecret && $existingProtectedSecret === null) {
             throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\MissingCredentialException::forAuthMethod($command->authMethod);
         }
      }

       $this->transaction->begin();
      try {
         if ($connection->name()->value() !== $command->name->value()) {
             $connection->rename($command->name);
         }
         if ($connection->endpoint()->value() !== $command->endpoint->value()) {
             $connection->changeEndpoint($command->endpoint);
         }
         if ($authMethodChanged) {
             $connection->changeAuthMethod($command->authMethod);
         }
         if ($connection->testPath()->value() !== $command->testPath->value()) {
             $connection->changeTestPath($command->testPath);
         }
         if ($hasNewSecret) {
             $connection->invalidateValidation();
         }

          $connection = $this->repository->save($connection);

         if (!$command->authMethod->requiresVaultedCredentials()) {
            if ($existingProtectedSecret !== null) {
                $this->secretStore->delete($command->id);
            }
         } else if ($hasNewSecret) {
             $this->secretStore->put(
                 $command->id,
                 $this->vault->protect((string) $command->plaintextSecret)
             );
         }

          $this->transaction->commit();
          return $connection;
      } catch (\Throwable $exception) {
          $this->transaction->rollback();
          throw $exception;
      }
   }
}
