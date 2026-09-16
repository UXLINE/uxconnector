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

/**
 * Outcome of a CredentialVaultRekeyService run.
 */
final readonly class CredentialVaultRekeyResult
{
    /**
     * @param array<int, string> $errors AuthConfig id => error message,
     *        for every secret that failed to rotate.
     */
   public function __construct(
        public int $rotated,
        public int $skipped,
        public int $failed,
        public array $errors = []
    ) {
   }

   public function isFullySuccessful(): bool {
       return $this->failed === 0;
   }
}
