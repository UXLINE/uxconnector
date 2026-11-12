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

namespace GlpiPlugin\Uxconnector\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Application\Engine\Command\CreateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthMethodNotSupportedException;
use GlpiPlugin\Uxconnector\Application\Engine\Exception\MissingCredentialException;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException;
use GlpiPlugin\Uxconnector\Domain\Engine\Exception\InsecureCredentialTransportException;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\OriginBoundCredential;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;

/**
 * Orchestrates the "create an AuthConfig" use case.
 *
 * Sequence: begin → create aggregate → capture events → persist →
 * protect & store the credential (if any) → commit → dispatch events.
 *
 * RULE-036/060 (transaction), RULE-061 (event publication) : this
 * Service never contains a business rule itself - those live in
 * AuthConfig and AuthMethod. It only sequences ports.
 *
 * Deliberate deviation from the naive "release events after
 * repository->save()" sequence found elsewhere in the codebase:
 * AuthConfigRepository::save() reloads the aggregate from the
 * database after persisting it (see mustFindById()), and
 * AuthConfig::reconstitute() never records events. Releasing events
 * from that reloaded instance would silently lose them. This Service
 * therefore captures releaseEvents() on the *original*, freshly
 * created aggregate, before it is persisted, and only dispatches them
 * after the transaction has committed successfully.
 */
final class CreateAuthConfigService
{
    public function __construct(
        private readonly AuthConfigRepositoryInterface $repository,
        private readonly AuthConfigSecretStoreInterface $secretStore,
        private readonly CredentialVaultInterface $vault,
        private readonly DestinationPolicyInterface $destinationPolicy,
        private readonly TransactionManagerInterface $transaction,
        private readonly EventDispatcherInterface $events
    ) {
    }

    /**
     * @throws AuthMethodNotSupportedException if the chosen AuthMethod is not
     *         yet implemented by GenericHttpEngine.
     * @throws InsecureCredentialTransportException if reusable credentials
     *         would be sent over clear-text HTTP.
     * @throws ForbiddenDestinationException if the endpoint resolves to an
     *         address this plugin must never contact (SSRF protection -
     *         see DestinationPolicy). Re-checked at every execution by
     *         GenericHttpEngine regardless, since DNS can change after
     *         creation; this early check only gives immediate feedback.
     * @throws MissingCredentialException if the chosen AuthMethod requires
     *         a credential and none was supplied.
     */
    public function execute(#[\SensitiveParameter] CreateAuthConfigCommand $command): AuthConfig
    {
        if (!$command->authMethod->isImplemented()) {
            throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthMethodNotSupportedException::forMethod(
                $command->authMethod
            );
        }

        if ($command->authMethod->requiresVaultedCredentials() && !$command->endpoint->isSecure()) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\InsecureCredentialTransportException::forEndpoint(
                $command->authMethod,
                $command->endpoint,
            );
        }

        $this->destinationPolicy->assertAllowed($command->endpoint);

        if ($command->authMethod->requiresVaultedCredentials() && $command->plaintextSecret === null) {
            throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\MissingCredentialException::forAuthMethod(
                $command->authMethod
            );
        }

        $this->transaction->begin();

        try {
            $authConfig = AuthConfig::create(
                $command->name,
                $command->endpoint,
                $command->authMethod,
                $command->testPath,
                $command->connectorId,
            );

            $events = $authConfig->releaseEvents();

            $authConfig = $this->repository->save($authConfig);

            if ($command->plaintextSecret !== null) {
                $protectedSecret = $this->vault->protect(OriginBoundCredential::wrap(
                    $command->endpoint,
                    $command->authMethod,
                    $command->plaintextSecret,
                ));
                $authConfigId = $authConfig->id();
                if ($authConfigId === null) {
                    throw new \RuntimeException(
                        'Persisted AuthConfig has no identifier.',
                    );
                }

                $this->secretStore->put($authConfigId, $protectedSecret);
            }

            $this->transaction->commit();

            foreach ($events as $event) {
                $this->events->dispatch($event);
            }

            return $authConfig;
        } catch (\Throwable $exception) {
            $this->transaction->rollback();

            throw $exception;
        }
    }
}
