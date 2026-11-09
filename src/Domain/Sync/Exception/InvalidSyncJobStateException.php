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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Exception;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;

final class InvalidSyncJobStateException extends \DomainException
{
    public static function cannotActivateWithoutFieldMappings(): self
    {
        return new self(
            'Cannot activate a SyncJob that has no FieldMapping configured.',
        );
    }


    public static function cannotActivateWithoutOutboundConfig(): self
    {
        return new self('Cannot activate an outbound interaction without an outbound request configuration.');
    }

    public static function invalidWebhookKey(): self
    {
        return new self('Webhook key must be a 64-character hexadecimal token.');
    }

    public static function cannotActivateFromStatus(SyncJobStatus $current): self
    {
        return new self(sprintf(
            'Cannot activate a SyncJob from status "%s".',
            $current->value,
        ));
    }

    public static function cannotAssignIdTwice(): self
    {
        return new self('SyncJob identifier has already been assigned.');
    }

    public static function matchFieldNotMapped(MatchField $matchField): self
    {
        return new self(sprintf(
            'MatchField "%s" does not correspond to the targetField of any configured FieldMapping.',
            $matchField->value(),
        ));
    }

    public static function assetIdentityRequiresMappedIdentityField(): self
    {
        return new self(
            'MatchField "asset_identity" requires at least one mapped identity field: uuid, serial, otherserial or name.',
        );
    }
}
