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

use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

/**
 * Stores the *already-protected* secret payload produced by
 * CredentialVaultInterface::protect() for a given AuthConfig.
 *
 * Deliberately kept separate from AuthConfigRepositoryInterface: the
 * AuthConfig aggregate itself never carries any secret material, in
 * clear or protected form (see AuthConfig's class doc-block). Only
 * this dedicated store — backed by its own, more tightly
 * access-controlled table — ever persists a protected secret.
 *
 * This interface never accepts nor returns a plaintext secret: the
 * plaintext only ever exists transiently inside the Application
 * Service, between the Command and the CredentialVault call.
 */
interface AuthConfigSecretStoreInterface
{
   public function put(AuthConfigId $id, string $protectedSecret): void;

   public function get(AuthConfigId $id): ?string;

   public function delete(AuthConfigId $id): void;
}
