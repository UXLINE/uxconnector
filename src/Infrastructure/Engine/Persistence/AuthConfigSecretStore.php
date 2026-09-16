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

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence;

use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

/**
 * Implements the Engine bounded context's secret storage port on top
 * of AuthConfigSecretItem.
 *
 * This class never sees a plaintext secret: by the time put() is
 * called, CreateAuthConfigService has already turned it into an
 * opaque payload via CredentialVaultInterface::protect(). Symmetrically,
 * get() returns that same opaque payload untouched — decryption is
 * the caller's responsibility, through the same CredentialVaultInterface.
 */
final class AuthConfigSecretStore implements AuthConfigSecretStoreInterface
{
   public function put(AuthConfigId $id, string $protectedSecret): void {
       $existing = $this->findRowFor($id);

       $model = new AuthConfigSecretItem();

       $data = [
           'plugin_uxconnector_authconfigs_id' => $id->value(),
           'protected_secret'                  => $protectedSecret,
       ];

       if ($existing !== null) {
           $data['id'] = (int) $existing['id'];

          if (!$model->update($data)) {
              throw new \RuntimeException(sprintf(
                  'Failed to update the protected secret for AuthConfig #%d.',
                  $id->value()
              ));
          }

           return;
       }

       if ($model->add($data) === false) {
           throw new \RuntimeException(sprintf(
               'Failed to store the protected secret for AuthConfig #%d.',
               $id->value()
           ));
       }
   }

   public function get(AuthConfigId $id): ?string {
       $row = $this->findRowFor($id);

       return $row['protected_secret'] ?? null;
   }

   public function delete(AuthConfigId $id): void {
       $row = $this->findRowFor($id);

      if ($row === null) {
          return;
      }

       $model = new AuthConfigSecretItem();

       // RULE-032 (adapted) : purge, not soft-delete — a credential
       // must never linger in GLPI's trash.
      if (!$model->delete(['id' => (int) $row['id']], true)) {
          throw new \RuntimeException(sprintf(
              'Failed to delete the protected secret for AuthConfig #%d.',
              $id->value()
          ));
      }
   }

    /**
     * @return array<string, mixed>|null
     */
   private function findRowFor(AuthConfigId $id): ?array {
       $model = new AuthConfigSecretItem();

       $rows = $model->find(['plugin_uxconnector_authconfigs_id' => $id->value()], [], 1);

       $row = $rows === [] ? null : reset($rows);

       return $row === false ? null : $row;
   }
}
