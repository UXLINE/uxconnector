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

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthConfigStatus;

/**
 * Raised when a business transition is attempted from a status that
 * does not allow it (e.g. enabling a config that has never been
 * successfully tested).
 */
final class InvalidAuthConfigStateException extends \DomainException
{
    public static function cannotActivateFromStatus(AuthConfigStatus $current): self
    {
        return new self(sprintf(
            'Cannot activate an AuthConfig from status "%s". It must first reach "%s".',
            $current->value,
            AuthConfigStatus::TESTING->value,
        ));
    }

    public static function cannotAssignIdTwice(): self
    {
        return new self('AuthConfig identifier has already been assigned.');
    }
}
