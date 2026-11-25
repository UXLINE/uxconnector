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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Entity;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ObjectLinkId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/**
 * Stable association between an external record and a GLPI item, scoped to the connection
 * and external object type. The creating job is retained for traceability, not lookup identity.
 * Inbound and outbound interactions on the same connection share this association.
 *
 * MatchField is used only for initial reconciliation. Subsequent writes use the associated
 * GLPI identifier, avoiding ambiguous matches on duplicate business values. The target
 * entity is resolved from the job at execution time and checked by the writer; it is not
 * duplicated here. This aggregate enforces invariants without SQL or persistence concerns.
 */
final class ObjectLink
{
    private ?ObjectLinkId $id = null;

    private DateTimeImmutable $lastSeenAt;

    private function __construct(
        private readonly SyncJobId $syncJobId,
        private readonly AuthConfigReference $authConfigReference,
        private readonly ExternalId $externalId,
        private readonly TargetItemtype $itemtype,
        private int $itemId,
        private readonly string $externalObjectType
    ) {
        $this->lastSeenAt = new DateTimeImmutable();
    }

    /**
     * Creates a brand new ObjectLink - either after a CREATE (a
     * GLPI item was just added) or a LINK (an existing, previously
     * unknown GLPI item was adopted after a single MatchField match).
     */
    public static function create(
        SyncJobId $syncJobId,
        AuthConfigReference $authConfigReference,
        ExternalId $externalId,
        TargetItemtype $itemtype,
        int $itemId,
        ?string $externalObjectType = null
    ): self {
        return new self(
            $syncJobId,
            $authConfigReference,
            $externalId,
            $itemtype,
            $itemId,
            self::normalizeExternalObjectType($externalObjectType ?? $itemtype->value)
        );
    }

    /**
     * Rehydrates an existing ObjectLink from persistence.
     */
    public static function reconstitute(
        ObjectLinkId $id,
        SyncJobId $syncJobId,
        AuthConfigReference $authConfigReference,
        ExternalId $externalId,
        TargetItemtype $itemtype,
        int $itemId,
        DateTimeImmutable $lastSeenAt,
        ?string $externalObjectType = null
    ): self {
        $link = new self(
            $syncJobId,
            $authConfigReference,
            $externalId,
            $itemtype,
            $itemId,
            self::normalizeExternalObjectType($externalObjectType ?? $itemtype->value)
        );
        $link->id = $id;
        $link->lastSeenAt = $lastSeenAt;

        return $link;
    }

    public function assignId(ObjectLinkId $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('ObjectLink identifier has already been assigned.');
        }

        $this->id = $id;
    }

    /**
     * Records that this link was used again by the most recent
     * synchronization run - a simple, queryable freshness signal, not
     * itself a business invariant.
     */
    public function touch(): void
    {
        $this->lastSeenAt = new DateTimeImmutable();
    }

    public function id(): ?ObjectLinkId
    {
        return $this->id;
    }


    public function syncJobId(): SyncJobId
    {
        return $this->syncJobId;
    }

    public function authConfigReference(): AuthConfigReference
    {
        return $this->authConfigReference;
    }

    public function externalId(): ExternalId
    {
        return $this->externalId;
    }

    public function itemtype(): TargetItemtype
    {
        return $this->itemtype;
    }

    public function itemId(): int
    {
        return $this->itemId;
    }

    public function externalObjectType(): string
    {
        return $this->externalObjectType;
    }

    private static function normalizeExternalObjectType(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid external object type.');
        }
        return $value;
    }

    public function lastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
