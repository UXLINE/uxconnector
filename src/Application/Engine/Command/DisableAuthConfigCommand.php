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

namespace GlpiPlugin\Uxconnector\Application\Engine\Command;

use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

final readonly class DisableAuthConfigCommand
{
   public function __construct(
        public AuthConfigId $authConfigId
    ) {
   }
}
