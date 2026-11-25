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

use GlpiPlugin\Uxconnector\Application\Engine\DTO\CredentialVaultRekeyResult;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;

/**
 * Re-encrypts stored credentials between two explicitly supplied vault implementations.
 * Normal GLPIKey rotation uses GLPI's security:change_key command and secured_fields hook.
 * This service is reserved for controlled migrations with both vaults available. It does
 * not derive keys or read environment key settings. The retired standalone CLI utility
 * points administrators to GLPI's native key rotation command.
 */
final class CredentialVaultRekeyService
{
    public function __construct(
        private readonly AuthConfigRepositoryInterface $authConfigRepository,
        private readonly AuthConfigSecretStoreInterface $secretStore
    ) {
    }

    public function execute(
        CredentialVaultInterface $oldVault,
        CredentialVaultInterface $newVault
    ): CredentialVaultRekeyResult {
        $rotated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->authConfigRepository->findAll() as $authConfig) {
            $id = $authConfig->id();

            if ($id === null) {
                continue;
            }

            $protectedSecret = $this->secretStore->get($id);

            if ($protectedSecret === null) {
                // No secret stored for this AuthConfig (e.g.
                // AuthMethod::NONE) - nothing to rotate.
                $skipped++;

                continue;
            }

            try {
                $plaintextSecret = $oldVault->reveal($protectedSecret);
                $reProtectedSecret = $newVault->protect($plaintextSecret);

                $this->secretStore->put($id, $reProtectedSecret);

                $rotated++;
            } catch (\Throwable $exception) {
                // One AuthConfig's secret failing to rotate (e.g.
                // already rotated by a previous partial run, or
                // corrupted) must never abort the rest of the batch -
                // same isolation principle as
                // ExecuteSyncJobService::applyFieldMappings().
                $failed++;
                $errors[$id->value()] = $exception->getMessage();
            }
        }

        return new CredentialVaultRekeyResult($rotated, $skipped, $failed, $errors);
    }
}
