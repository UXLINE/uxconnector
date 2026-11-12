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

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ObjectLinkId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final class ObjectLinkPersistenceMapper
{
    /**
     * @param array<string, mixed> $row Raw row as returned by CommonDBTM::fields.
     */
    public function toDomain(array $row, ?string $externalObjectTypeOverride = null): ObjectLink
    {
        $this->assertHasRequiredFields($row);

        $itemtype = TargetItemtype::tryFrom((string) $row['itemtype']);

        if ($itemtype === null) {
            throw new \RuntimeException(sprintf(
                'Corrupted ObjectLink row #%s: unknown itemtype value.',
                (string) $row['id'],
            ));
        }

        return ObjectLink::reconstitute(
            new ObjectLinkId((int) $row['id']),
            new SyncJobId((int) $row['plugin_uxconnector_syncjobs_id']),
            new AuthConfigReference((int) $row['auth_config_reference']),
            new ExternalId((string) $row['external_id']),
            $itemtype,
            (int) $row['item_id'],
            new DateTimeImmutable((string) $row['last_seen_at']),
            $externalObjectTypeOverride !== null && trim($externalObjectTypeOverride) !== ''
                ? $externalObjectTypeOverride
                : ((($row['external_object_type'] ?? '') === 'record' || trim(
                    (string) ($row['external_object_type'] ?? '')
                ) === '')
                    ? $itemtype->value
                    : (string) $row['external_object_type']),
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toPersistence(ObjectLink $objectLink): array
    {
        $data = [
            'plugin_uxconnector_syncjobs_id' => $objectLink->syncJobId()->value(),
            'auth_config_reference' => $objectLink->authConfigReference()->value(),
            'external_id'           => $objectLink->externalId()->value(),
            'external_object_type'  => $objectLink->externalObjectType(),
            'itemtype'               => $objectLink->itemtype()->value,
            'item_id'                => $objectLink->itemId(),
            'last_seen_at'           => $objectLink->lastSeenAt()->format('Y-m-d H:i:s'),
        ];

        if ($objectLink->id() !== null) {
            $data['id'] = $objectLink->id()->value();
        }

        return $data;
    }

    public function assignGeneratedId(ObjectLink $objectLink, int $generatedId): void
    {
        $objectLink->assignId(new ObjectLinkId($generatedId));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertHasRequiredFields(array $row): void
    {
        foreach (
            ['id', 'plugin_uxconnector_syncjobs_id', 'auth_config_reference', 'external_id', 'itemtype', 'item_id',
                'last_seen_at'] as $field
        ) {
            if (!array_key_exists($field, $row)) {
                throw new \RuntimeException(sprintf(
                    'Corrupted ObjectLink row: missing field "%s".',
                    $field,
                ));
            }
        }
    }
}
