<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ObjectLinkId;

final class ObjectLinkRepository implements ObjectLinkRepositoryInterface
{
    public function __construct(private readonly ObjectLinkPersistenceMapper $mapper)
    {
    }

    public function save(ObjectLink $objectLink): ObjectLink
    {
        $data = $this->mapper->toPersistence($objectLink);
        $model = new ObjectLinkItem();
        if ($objectLink->id() === null) {
            $generatedId = $model->add($data);
            if ($generatedId === false) {
                throw new \RuntimeException('Failed to persist a new ObjectLink.');
            }
            $this->mapper->assignGeneratedId($objectLink, (int) $generatedId);
            return $objectLink;
        }
        if (!$model->update($data)) {
            throw new \RuntimeException(sprintf('Failed to update ObjectLink #%d.', $objectLink->id()->value()));
        }
        return $objectLink;
    }

    public function findByExternalId(
        AuthConfigReference $authConfigReference,
        string $externalObjectType,
        ExternalId $externalId
    ): ?ObjectLink {
        $rows = (new ObjectLinkItem())->find([
            'auth_config_reference' => $authConfigReference->value(),
            'external_object_type' => strtolower(trim($externalObjectType)),
            'external_id' => $externalId->value(),
        ], [], 1);
        foreach ($rows as $row) {
            return $this->mapper->toDomain($row);
        }

        // Upgrade compatibility: links created before 2.8.0 receive the
        // neutral persisted value "record" until they are touched again.
        $legacyRows = (new ObjectLinkItem())->find([
            'auth_config_reference' => $authConfigReference->value(),
            'external_object_type' => 'record',
            'external_id' => $externalId->value(),
        ], [], 1);
        foreach ($legacyRows as $row) {
            // Rehydrate legacy "record" rows with the requested semantic type.
            // The next save/touch then upgrades the persisted value instead of
            // incorrectly replacing it with the GLPI itemtype (e.g. ticket).
            return $this->mapper->toDomain($row, $externalObjectType);
        }
        return null;
    }

    public function findByGlpiItem(
        AuthConfigReference $authConfigReference,
        TargetItemtype $itemtype,
        int $itemId,
        ?string $externalObjectType = null
    ): ?ObjectLink {
        $criteria = [
            'auth_config_reference' => $authConfigReference->value(),
            'itemtype' => $itemtype->value,
            'item_id' => $itemId,
        ];
        if ($externalObjectType !== null && trim($externalObjectType) !== '') {
            $criteria['external_object_type'] = strtolower(trim($externalObjectType));
        }
        $rows = (new ObjectLinkItem())->find($criteria, [], 1);
        foreach ($rows as $row) {
            return $this->mapper->toDomain($row);
        }

        if ($externalObjectType !== null && trim($externalObjectType) !== '') {
            $legacyCriteria = [
                'auth_config_reference' => $authConfigReference->value(),
                'itemtype' => $itemtype->value,
                'item_id' => $itemId,
                'external_object_type' => 'record',
            ];
            $legacyRows = (new ObjectLinkItem())->find($legacyCriteria, [], 1);
            foreach ($legacyRows as $row) {
                return $this->mapper->toDomain($row, $externalObjectType);
            }
        }

        return null;
    }

    public function delete(ObjectLinkId $id): void
    {
        (new ObjectLinkItem())->delete(['id' => $id->value()], true);
    }
}
