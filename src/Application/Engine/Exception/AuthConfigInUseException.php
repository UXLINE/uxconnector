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

final class AuthConfigInUseException extends \RuntimeException
{
    public static function forId(AuthConfigId $id): self
    {
        return new self(sprintf(
            'AuthConfig #%d cannot be deleted because it is referenced by at least one synchronization '
                . 'job. Delete or reconfigure the dependent jobs first.',
            $id->value(),
        ));
    }
}
