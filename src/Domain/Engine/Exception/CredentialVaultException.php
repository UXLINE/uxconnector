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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Exception;

/**
 * Raised when a secret cannot be protected or revealed by the
 * CredentialVault (missing encryption key, corrupted payload,
 * tampering detected, ...).
 *
 * Deliberately never carries the plaintext secret nor the protected
 * payload in its message, to avoid leaking sensitive material into
 * logs or exception traces.
 */
final class CredentialVaultException extends \RuntimeException
{
   public static function encryptionKeyUnavailable(): self {
       return new self(
           'No encryption key is available to protect or reveal AuthConfig secrets.'
       );
   }

   public static function protectionFailed(): self {
       return new self('The secret could not be encrypted.');
   }

   public static function revealFailed(): self {
       return new self(
           'The stored secret could not be decrypted: it may be corrupted, ' .
           'tampered with, or was encrypted with a different key.'
       );
   }

   public static function malformedPayload(): self {
       return new self('The stored secret payload is malformed.');
   }
}
