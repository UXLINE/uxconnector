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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Security;

use GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException;

/**
 * Port of the Engine bounded context towards the secret storage
 * mechanism used to protect AuthConfig credentials (API keys, OAuth2
 * client secrets, passwords, bearer tokens, ...).
 *
 * RULE-VALUEOBJECT-003/004 (adapted): the Domain never knows how or
 * with which algorithm a secret is encrypted. It only knows it can
 * ask the vault to protect() a plaintext secret and reveal() it back
 * when the GenericHttpEngine needs to authenticate an outgoing
 * request. The concrete implementation (GLPIKey / libsodium via GLPI's glpicrypt.key) lives in Infrastructure\Engine\Security.
 */
interface CredentialVaultInterface
{
    /**
     * Encrypts a plaintext secret and returns an opaque, storable
     * representation of it.
     *
     * @throws CredentialVaultException if the secret could not be protected.
     */
    public function protect(string $plaintextSecret): string;

    /**
     * Decrypts a value previously produced by protect().
     *
     * @throws CredentialVaultException if the value cannot be decrypted
     *         (corrupted payload, tampering detected, wrong key, ...).
     */
    public function reveal(string $protectedSecret): string;
}
