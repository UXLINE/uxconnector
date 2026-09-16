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

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthConfigStatus;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Exception\InvalidAuthConfigStateException;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * Converts between the AuthConfig aggregate (Domain) and the flat
 * array structure expected/returned by CommonDBTM (Infrastructure).
 *
 * RULE-COMPOSER-005 / Clean Architecture : this is the only class
 * allowed to know both the aggregate's shape and the SQL column
 * names. The Repository delegates every conversion to it, so neither
 * the Domain nor the Application layer ever touches raw SQL rows.
 *
 * The secret material itself (protected by the CredentialVault) is
 * intentionally *not* mapped here: this Mapper only ever sees the
 * already-protected, opaque payload string produced by
 * CredentialVaultInterface::protect(), never the plaintext secret.
 */
final class AuthConfigPersistenceMapper
{
    /**
     * Converts a persisted row into a fully hydrated AuthConfig
     * aggregate.
     *
     * @param array<string, mixed> $row Raw row as returned by CommonDBTM::fields.
     */
   public function toDomain(array $row): AuthConfig {
       $this->assertHasRequiredFields($row);

       $authMethod = AuthMethod::tryFrom((string) $row['auth_method']);
       $status = AuthConfigStatus::tryFrom((string) $row['status']);

      if ($authMethod === null || $status === null) {
          throw new \RuntimeException(sprintf(
              'Corrupted AuthConfig row #%s: unknown auth_method or status value.',
              (string) $row['id']
          ));
      }

       return AuthConfig::reconstitute(
           new AuthConfigId((int) $row['id']),
           new AuthConfigName((string) $row['name']),
           new Endpoint((string) $row['endpoint']),
           $authMethod,
           $status,
           new DateTimeImmutable((string) $row['date_creation']),
           new DateTimeImmutable((string) $row['date_mod']),
           new TestPath((string) ($row['test_path'] ?? '/')),
           (string) ($row['connector_id'] ?? 'custom')
       );
   }

    /**
     * Converts an AuthConfig aggregate into the flat array expected
     * by CommonDBTM::add()/update().
     *
     * @return array<string, int|string>
     */
   public function toPersistence(AuthConfig $authConfig): array {
       $data = [
           'name'        => $authConfig->name()->value(),
           'endpoint'    => $authConfig->endpoint()->value(),
           'test_path'   => $authConfig->testPath()->value(),
           'connector_id' => $authConfig->connectorId(),
           'auth_method' => $authConfig->authMethod()->value,
           'status'      => $authConfig->status()->value,
       ];

       $id = $authConfig->id();

       if ($id !== null) {
           $data['id'] = $id->value();
       }

       return $data;
   }

    /**
     * Assigns the identifier generated at persistence time onto the
     * aggregate, using the same guarded path as any other Repository
     * (AuthConfig::assignId() forbids reassignment).
     *
     * @throws InvalidAuthConfigStateException if the aggregate already has an id.
     */
   public function assignGeneratedId(AuthConfig $authConfig, int $generatedId): void {
       $authConfig->assignId(new AuthConfigId($generatedId));
   }

    /**
     * @param array<string, mixed> $row
     */
   private function assertHasRequiredFields(array $row): void {
      foreach (['id', 'name', 'endpoint', 'auth_method', 'status', 'date_creation', 'date_mod'] as $field) {
         if (!array_key_exists($field, $row)) {
            throw new \RuntimeException(sprintf(
                'Cannot hydrate AuthConfig: missing "%s" field in persisted row.',
                $field
            ));
         }
      }
   }
}
