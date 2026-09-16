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
 * Re-encrypts every stored AuthConfig secret from an old
 * CredentialVaultInterface to a new one.
 *
 * CORRECTIF SÉCURITÉ (revue de code externe) : UXConnector delegates credential encryption to GLPIKey. In normal runtime,
 * GLPI's `security:change_key` command handles rotation because the plugin
 * declares its encrypted database field through `secured_fields`. This
 * service remains available only for explicit maintenance/migration cases.
 *
 * Doit être exécuté avec **les deux clés simultanément disponibles**
 * (avant l'ancienne configuration `GLPI_INSTANCE_KEY` ait été
 * définitivement écrasée) — voir tools/rekey-credential-vault.php
 * pour le point d'entrée opérationnel destiné à un administrateur.
 *
 * RULE-STRUCTURE-002 : responsabilité unique — orchestre uniquement
 * la boucle de re-chiffrement ; ni la dérivation des clés
 * (`OpenSslCredentialVault`), ni la lecture de `GLPI_INSTANCE_KEY`
 * n'est de son ressort.
 */
final class CredentialVaultRekeyService
{
   public function __construct(
        private readonly AuthConfigRepositoryInterface $authConfigRepository,
        private readonly AuthConfigSecretStoreInterface $secretStore
    ) {
   }

   public function execute(CredentialVaultInterface $oldVault, CredentialVaultInterface $newVault): CredentialVaultRekeyResult {
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
             // AuthMethod::NONE) — nothing to rotate.
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
             // corrupted) must never abort the rest of the batch —
             // same isolation principle as
             // ExecuteSyncJobService::applyFieldMappings().
             $failed++;
             $errors[$id->value()] = $exception->getMessage();
         }
      }

       return new CredentialVaultRekeyResult($rotated, $skipped, $failed, $errors);
   }
}
