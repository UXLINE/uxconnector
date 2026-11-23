<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\OriginBoundCredential;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;

/** Upgrade-only operation: establish the current stored origin as the baseline, without changing statuses. */
final readonly class BindLegacyCredentialsService
{
    public function __construct(
        private AuthConfigRepositoryInterface $configs,
        private AuthConfigSecretStoreInterface $secrets,
        private CredentialVaultInterface $vault,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(): void
    {
        $this->transactions->begin();
        try {
            foreach ($this->configs->findAll() as $config) {
                $id = $config->id();
                if ($id === null) {
                    throw new \RuntimeException('A stored connection has no identifier.');
                }
                $protected = $this->secrets->get($id);
                if ($protected === null) {
                    continue;
                }
                $plain = $this->vault->reveal($protected);
                if ($plain === '') {
                    throw new \RuntimeException('An existing credential cannot be decrypted.');
                }
                if (OriginBoundCredential::isBound($plain)) {
                    // Never rebind an already protected secret to a changed destination or method.
                    OriginBoundCredential::unwrap($config->endpoint(), $config->authMethod(), $plain);
                    continue;
                }
                $bound = OriginBoundCredential::wrap($config->endpoint(), $config->authMethod(), $plain);
                $encrypted = $this->vault->protect($bound);
                if ($encrypted === $bound || !hash_equals($bound, $this->vault->reveal($encrypted))) {
                    throw new \RuntimeException('Credential encryption verification failed.');
                }
                $this->secrets->put($id, $encrypted);
                $stored = $this->secrets->get($id);
                if ($stored === null || !hash_equals($bound, $this->vault->reveal($stored))) {
                    throw new \RuntimeException('Credential storage verification failed.');
                }
            }
            $this->transactions->commit();
        } catch (\Throwable) {
            $this->transactions->rollback();
            // Do not forward vault errors, which may include sensitive input from an adapter.
            throw new \RuntimeException(
                'Unable to bind connection credentials. Check the GLPI key and connection settings, then retry the upgrade.',
            );
        }
    }
}
