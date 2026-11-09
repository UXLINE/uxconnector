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
 * ObjectLink is the aggregate root remembering that a given external
 * record (identified by ExternalId + external object type and scoped to one
 * Connection/AuthConfig) has already been reconciled to a specific GLPI item.
 * The creating SyncJob id is retained for traceability, but it is no longer part
 * of lookup semantics: inbound and outbound interactions on the same connection
 * deliberately share the same equivalence.
 *
 * CORRECTIF (réconciliation, revue de code externe) : avant cet
 * agrégat, GlpiItemWriter retrouvait un enregistrement existant par
 * une simple recherche `WHERE <matchField> = <valeur> LIMIT 1` sur la
 * table de l'itemtype cible - si plusieurs enregistrements GLPI
 * partageaient la même valeur (deux Computer de même serial dans deux
 * entités différentes, par exemple), le premier trouvé était
 * silencieusement modifié, potentiellement le mauvais.
 *
 * ObjectLink change ce comportement de fond en comble : le champ
 * métier (MatchField, ex. "serial") ne sert plus qu'à la toute
 * première tentative de rapprochement d'un ExternalId encore inconnu.
 * Une fois ce lien créé, toute synchronisation suivante retrouve
 * l'objet GLPI directement par son identifiant (`itemId`), sans plus
 * jamais interroger la valeur métier - élimine le risque de collision
 * pour tout enregistrement déjà rapproché une première fois.
 *
 * ObjectLink ne duplique volontairement pas l'entité GLPI : son
 * identité fonctionnelle est bornée par la Connection/AuthConfig, le type
 * d'objet externe et l'identifiant externe. L'affectation d'entité est résolue à chaque
 * exécution par le SyncJob puis appliquée par GlpiItemWriter ; la
 * persister aussi ici créerait deux sources de vérité.
 *
 * RULE-ENTITY-001 à 005 : propriétés privées, invariants dans cette
 * classe, aucune requête SQL, comportement réel via des méthodes
 * métier explicites.
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
        return new self($syncJobId, $authConfigReference, $externalId, $itemtype, $itemId, self::normalizeExternalObjectType($externalObjectType ?? $itemtype->value));
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
        $link = new self($syncJobId, $authConfigReference, $externalId, $itemtype, $itemId, self::normalizeExternalObjectType($externalObjectType ?? $itemtype->value));
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
