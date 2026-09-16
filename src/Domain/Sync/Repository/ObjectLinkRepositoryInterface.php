<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Repository;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ObjectLinkId;

interface ObjectLinkRepositoryInterface
{
   public function save(ObjectLink $objectLink): ObjectLink;

    /** Connection-level external identity lookup, shared by all interactions. */
   public function findByExternalId(
        AuthConfigReference $authConfigReference,
        string $externalObjectType,
        ExternalId $externalId
    ): ?ObjectLink;

    /** Resolves the external equivalent of an already linked GLPI object. */
   public function findByGlpiItem(
        AuthConfigReference $authConfigReference,
        TargetItemtype $itemtype,
        int $itemId,
        ?string $externalObjectType = null
    ): ?ObjectLink;

   public function delete(ObjectLinkId $id): void;
}
