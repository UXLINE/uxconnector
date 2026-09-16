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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Repository;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

/**
 * Port of the Engine bounded context towards the persistence
 * mechanism. The Domain only knows this interface; the concrete
 * implementation (backed by a CommonDBTM table) lives in
 * Infrastructure\Engine\Persistence (RULE-COMPOSER, Clean
 * Architecture: dependencies point inwards).
 */
interface AuthConfigRepositoryInterface
{
    /**
     * Persists a new or existing AuthConfig and returns the aggregate
     * with its identifier assigned.
     */
   public function save(AuthConfig $authConfig): AuthConfig;

   public function findById(AuthConfigId $id): ?AuthConfig;

    /**
     * @return list<AuthConfig>
     */
   public function findAll(): array;

   public function delete(AuthConfigId $id): void;

   public function exists(AuthConfigId $id): bool;
}
