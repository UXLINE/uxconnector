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

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

/**
 * Implements the Engine bounded context's persistence port on top of
 * GLPI's CommonDBTM (via AuthConfigItem) and MariaDB.
 *
 * RULE-032/033 (adapted) : every lookup goes through a Value Object
 * identity, save() decides INSERT vs UPDATE on the aggregate's
 * behalf, and this class is the *only* place in the plugin where SQL
 * (through CommonDBTM) and the Domain meet.
 */
final class AuthConfigRepository implements AuthConfigRepositoryInterface
{
   public function __construct(
        private readonly AuthConfigPersistenceMapper $mapper,
        private readonly AuthConfigSecretStoreInterface $secretStore
    ) {
   }

   public function save(AuthConfig $authConfig): AuthConfig {
       $data = $this->mapper->toPersistence($authConfig);
       $model = new AuthConfigItem();

      if ($authConfig->id() === null) {
          $generatedId = $model->add($data);

         if ($generatedId === false) {
            throw new \RuntimeException('Failed to persist a new AuthConfig.');
         }

          $this->mapper->assignGeneratedId($authConfig, (int) $generatedId);

          $authConfigId = $authConfig->id();
         if ($authConfigId === null) {
            throw new \LogicException(
               'Persisted AuthConfig has no identifier.'
            );
         }

          return $this->mustFindById($authConfigId);
      }

      if (!$model->update($data)) {
          throw new \RuntimeException(sprintf(
              'Failed to update AuthConfig #%d.',
              $authConfig->id()->value()
          ));
      }

       return $this->mustFindById($authConfig->id());
   }

   public function findById(AuthConfigId $id): ?AuthConfig {
       $model = new AuthConfigItem();

      if (!$model->getFromDB($id->value())) {
          return null;
      }

       return $this->mapper->toDomain($model->fields);
   }

    /**
     * @return list<AuthConfig>
     */
   public function findAll(): array {
       $model = new AuthConfigItem();

       $rows = $model->find();

       return array_values(array_map(
           fn (array $row): AuthConfig => $this->mapper->toDomain($row),
           $rows
       ));
   }

   public function delete(AuthConfigId $id): void {
       $model = new AuthConfigItem();

       // RULE-032 (adapted) : purge, not soft-delete — AuthConfig
       // credentials must not linger in GLPI's trash (see project
       // convention: DELETE=8 moves to trash, PURGE=16 removes
       // permanently; secrets always use PURGE).
       // Delete the protected credential first. The Application Service wraps
       // both operations in one transaction, so a later persistence failure
       // restores the secret together with its parent configuration.
       $this->secretStore->delete($id);

      if (!$model->delete(['id' => $id->value()], true)) {
          throw new \RuntimeException(sprintf(
              'Failed to delete AuthConfig #%d.',
              $id->value()
          ));
      }
   }

   public function exists(AuthConfigId $id): bool {
       $model = new AuthConfigItem();

       return $model->getFromDB($id->value());
   }

   private function mustFindById(AuthConfigId $id): AuthConfig {
       $authConfig = $this->findById($id);

      if ($authConfig === null) {
          throw new \RuntimeException(sprintf(
              'AuthConfig #%d was just persisted but could not be reloaded.',
              $id->value()
          ));
      }

       return $authConfig;
   }
}
