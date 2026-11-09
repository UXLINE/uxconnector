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

namespace GlpiPlugin\Uxconnector\Application\Engine\Exception;

use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

final class AuthConfigNotFoundException extends \RuntimeException
{
    public static function forId(AuthConfigId $id): self
    {
        return new self(sprintf('AuthConfig #%d does not exist.', $id->value()));
    }
}
