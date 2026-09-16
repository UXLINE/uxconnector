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

namespace GlpiPlugin\Uxconnector\Application\Engine\DTO;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;

/**
 * Read-only, serializable representation of an AuthConfig, safe to
 * hand to a template or a REST response.
 *
 * RULE-DTO-001/002/003 : immutable, the Entity is never exposed
 * directly, and this class contains zero business logic — only a
 * one-way conversion from the aggregate's Value Objects/Enums to
 * plain scalars. It never carries the protected secret (see
 * AuthConfigSecretStoreInterface): that value never leaves the
 * Engine bounded context's Infrastructure layer in any form.
 */
final readonly class AuthConfigDTO
{
   public function __construct(
        public int $id,
        public string $name,
        public string $endpoint,
        public string $testPath,
        public string $connectorId,
        public string $authMethod,
        public string $authMethodLabel,
        public string $status,
        public string $statusLabel,
        public bool $isUsable,
        public string $createdAt,
        public string $updatedAt
    ) {
   }

   public static function fromEntity(AuthConfig $authConfig): self {
       $id = $authConfig->id();

      if ($id === null) {
          throw new \LogicException(
              'Cannot build an AuthConfigDTO from an AuthConfig that has not been persisted yet.'
          );
      }

       return new self(
           id: $id->value(),
           name: $authConfig->name()->value(),
           endpoint: $authConfig->endpoint()->value(),
           testPath: $authConfig->testPath()->value(),
           connectorId: $authConfig->connectorId(),
           authMethod: $authConfig->authMethod()->value,
           authMethodLabel: $authConfig->authMethod()->label(),
           status: $authConfig->status()->value,
           statusLabel: $authConfig->status()->label(),
           isUsable: $authConfig->isUsable(),
           createdAt: $authConfig->createdAt()->format('Y-m-d H:i:s'),
           updatedAt: $authConfig->updatedAt()->format('Y-m-d H:i:s')
       );
   }
}
