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

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Security;

use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;

/**
 * Credential vault backed by GLPI's native GLPIKey service.
 *
 * GLPI stores its reversible-encryption key in config/glpicrypt.key and
 * protects values with libsodium (XChaCha20-Poly1305). UXConnector must use
 * that mechanism instead of inventing or expecting a GLPI_INSTANCE_KEY
 * constant. The protected_secret database field is declared through the
 * secured_fields plugin hook so GLPI can re-encrypt it during
 * `php bin/console security:change_key`.
 *
 * The historical class name is intentionally kept for BC with code/tests
 * that may still instantiate OpenSslCredentialVault directly. No OpenSSL
 * primitive is used anymore.
 */
final class OpenSslCredentialVault implements CredentialVaultInterface
{
   private readonly \GLPIKey $glpiKey;
   private readonly ?string $explicitKey;

    /**
     * @param string|null $explicitKey Optional key used only by maintenance /
     *                                 migration tooling. Runtime code leaves it
     *                                 null so GLPIKey reads config/glpicrypt.key.
     */
   public function __construct(?string $explicitKey = null, ?\GLPIKey $glpiKey = null) {
       $this->glpiKey = $glpiKey ?? new \GLPIKey();
       $this->explicitKey = $explicitKey === null
           ? null
           : self::normalizeExplicitKey($explicitKey);

      if ($this->explicitKey === null && $this->glpiKey->hasReadErrors()) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::encryptionKeyUnavailable();
      }
   }

   public function protect(string $plaintextSecret): string {
      if ($plaintextSecret === '') {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::protectionFailed();
      }
      try {
          $protected = $this->glpiKey->encrypt($plaintextSecret, $this->explicitKey);
      } catch (\Throwable) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::protectionFailed();
      }

      if ($protected === '') {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::protectionFailed();
      }

       return $protected;
   }

   public function reveal(string $protectedSecret): string {
      try {
          $plaintext = $this->glpiKey->decrypt($protectedSecret, $this->explicitKey);
      } catch (\Throwable) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::revealFailed();
      }

       // AuthConfig credentials are never intentionally stored as an empty
       // protected payload. GLPIKey returns an empty string when a non-empty
       // payload cannot be decoded/decrypted, so translate that core failure
       // into the bounded-context exception expected by callers.
      if ($protectedSecret !== '' && ($plaintext === null || $plaintext === '')) {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::revealFailed();
      }

       return $plaintext ?? '';
   }

    /**
     * GLPI's sodium cipher requires a 32-byte key. Native runtime keys already
     * have that size. Hashing non-native explicit values preserves the old
     * maintenance/test constructor contract without affecting normal runtime.
     */
   private static function normalizeExplicitKey(string $key): string {
      if ($key === '') {
          throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::encryptionKeyUnavailable();
      }

      if (strlen($key) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
          return $key;
      }

       return hash('sha256', $key, true);
   }
}
