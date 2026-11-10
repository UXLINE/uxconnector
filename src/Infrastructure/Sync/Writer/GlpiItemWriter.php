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

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Writer;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\GlpiTargetFieldsWriter;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ReconciliationContext;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\LinkedTargetReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
use GlpiPlugin\Uxconnector\Domain\Sync\Writer\ActionAwareSyncItemWriterInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime\SyncRuntimeGuard;

/**
 * Writes one mapped record into a GLPI itemtype (Computer, User, ...)
 * via that itemtype's own CommonDBTM class - never raw SQL INSERT/UPDATE
 * - so every GLPI-side behaviour a plugin is expected to respect
 * (hooks, logs) still runs normally.
 *
 * `CommonDBTM::add()`/`update()` do not themselves enforce the
 * interactive-user rights model (`can()`/`canCreateItem()`) - that
 * check is normally the calling Controller's responsibility. This
 * writer is deliberately never gated by it: UXConnector's CronTask
 * (and its "Run now" UI counterpart) acts as an explicit **system
 * actor** with its own authorization model - the plugin's own
 * `plugin_uxconnector_syncjob` right, checked once before a SyncJob
 * runs at all - not as a stand-in for an interactive GLPI user. An
 * earlier version of this doc-block implied CommonDBTM protected
 * rights automatically here; a code review correctly challenged that
 * claim, and this paragraph replaces it with the actual, intended
 * design.
 *
 * CORRECTIF (réconciliation, revue de code externe) : la stratégie de
 * rapprochement n'est plus une simple recherche
 * `WHERE <matchField> = <valeur> LIMIT 1`, qui pouvait silencieusement
 * modifier le mauvais enregistrement GLPI si plusieurs partageaient
 * la même valeur. Elle repose désormais sur `ObjectLink` :
 *
 *   1. `ObjectLink` déjà connu pour cet `ExternalId` → UPDATE direct
 *      par identifiant GLPI, aucune ambiguïté possible.
 *   2. Sinon, recherche par `MatchField` (première tentative
 *      uniquement) :
 *        - 0 résultat  → CREATE, puis mémorisation du lien ;
 *        - 1 résultat  → LINK (adoption d'un enregistrement GLPI déjà
 *          existant, créé manuellement ou par un import antérieur),
 *          puis mémorisation du lien ;
 *        - >1 résultat → COLLISION : refus d'écrire, plutôt que de
 *          deviner lequel est le bon.
 *
 * Security note: `$itemtype->glpiClassName()` only ever returns one
 * of TargetItemtype's fixed, curated cases - never a string derived
 * from the external system's response or from unvalidated
 * configuration - so the `new $className()` below can never
 * instantiate an arbitrary class (see TargetItemtype's doc-block).
 */
final class GlpiItemWriter implements ActionAwareSyncItemWriterInterface
{
    private string $lastAction = 'none';

    public function lastAction(): string
    {
        return $this->lastAction;
    }

    public function __construct(
        private readonly DBmysql $db,
        private readonly ObjectLinkRepositoryInterface $objectLinkRepository
    ) {
    }

    public function write(ReconciliationContext $context, array $mappedFields): bool
    {
        $this->lastAction = 'none';
        $email = null;
        if ($context->itemtype === TargetItemtype::USER && array_key_exists('email', $mappedFields)) {
            $email = (new GlpiUserEmailWriter($this->db))->normalize($mappedFields['email']);
            unset($mappedFields['email']);
        }
        $item = match ($context->itemtype) {
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::COMPUTER => new \Computer(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::MONITOR => new \Monitor(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::NETWORK_EQUIPMENT => new \NetworkEquipment(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::PERIPHERAL => new \Peripheral(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::PHONE => new \Phone(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::SIM_CARD => new \Item_DeviceSimcard(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::LOCATION => new \Location(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::LINE => new \Line(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::SOFTWARE_LICENSE => new \SoftwareLicense(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::PRINTER => new \Printer(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::USER => new \User(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::GROUP => new \Group(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::ENTITY => new \Entity(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::ITIL_CATEGORY => new \ITILCategory(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::ITIL_FOLLOWUP => new \ITILFollowup(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::DOCUMENT => new \Document(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::PROJECT => new \Project(),
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::TICKET => new \Ticket(),
        };

        // Never let externally-sourced data dictate a specific
        // primary key: strip any accidental "id" from the mapped
        // fields before deciding whether this is an update or a
        // creation.
        // Fields values must never be passed to CommonDBTM as unvalidated
        // keys. Resolve against the active Fields container *before* a core
        // write so a missing block does not produce a partial new record.
        $data = [];
        $fieldsValues = [];
        foreach ($mappedFields as $target => $value) {
            if (TargetFieldPolicy::isFieldsTarget((string) $target)) {
                $fieldsValues[substr((string) $target, 7)] = $value;
            } else {
                $data[$target] = $value;
            }
        }
        // Explicit ObjectLink resolution is required for cross-instance
        // associations. Never interpret a GLPI 10 primary key as a GLPI 11 id.
        foreach ($data as $field => $value) {
            if (!$value instanceof LinkedTargetReference) {
                continue;
            }
            if (
                $context->itemtype !== TargetItemtype::SIM_CARD
                || !in_array($field, ['lines_id', 'items_id', 'locations_id', 'users_id'], true)
            ) {
                throw new \RuntimeException('ObjectLink transformation is not permitted for this target field.');
            }
            $linked = $this->objectLinkRepository->findByExternalId(
                $context->authConfigReference,
                $value->externalObjectType,
                new ExternalId($value->externalId),
            );
            if ($linked === null) {
                throw new \RuntimeException(
                    'Referenced GLPI item has not yet been imported. Run the parent interaction first.'
                );
            }
            $expected = match ($field) {
                'lines_id' => [TargetItemtype::LINE],
                'locations_id' => [TargetItemtype::LOCATION],
                'users_id' => [TargetItemtype::USER],
                'items_id' => [TargetItemtype::PHONE, TargetItemtype::NETWORK_EQUIPMENT, TargetItemtype::COMPUTER],
            };
            if (!in_array($linked->itemtype(), $expected, true)) {
                throw new \RuntimeException('ObjectLink points to an incompatible GLPI itemtype.');
            }
            if (
                $field === 'items_id' && isset($data['itemtype'])
                && $data['itemtype'] !== ltrim($linked->itemtype()->glpiClassName(), '\\')
            ) {
                throw new \RuntimeException('Equipment type conflicts with ObjectLink target.');
            }
            $data[$field] = $linked->itemId();
        }
        $fieldsWriter = null;
        if ($fieldsValues !== []) {
            $fieldsWriter = new GlpiTargetFieldsWriter($this->db);
            $fieldsWriter->assertAvailable(ltrim($context->itemtype->glpiClassName(), '\\'), array_keys($fieldsValues));
        }
        if ($context->itemtype === TargetItemtype::SIM_CARD) {
            if ($context->entitiesId === null) {
                throw new \RuntimeException(
                    'SIM imports require an explicit target entity to prevent cross-entity associations.'
                );
            }
            if (!$this->validateSimCardReferences($data, $context->entitiesId)) {
                throw new \RuntimeException('SIM references are invalid or do not belong to the selected entity.');
            }
        }
        foreach (
            ['id', 'entities_id', 'is_deleted', 'is_template', 'template_name', 'date_mod',
                'date_creation'] as $reservedField
        ) {
            unset($data[$reservedField]);
        }

        // Safe bootstrap defaults for reference data created into an empty GLPI.
        // They can still be overridden later by the target configuration.
        if (
            $context->itemtype === \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::ENTITY
            && $context->entitiesId === null
        ) {
            $data['entities_id'] = 0;
        }
        if ($context->itemtype === \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::GROUP) {
            $data['is_recursive'] ??= 1;
        }
        if ($context->itemtype === \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::ITIL_CATEGORY) {
            $data['is_helpdeskvisible'] ??= 1;
            $data['is_incident'] ??= 1;
            $data['is_request'] ??= 1;
        }

        // CORRECTIF (gestion des entités, revue de code externe) :
        // $context->entitiesId est déjà résolu par ExecuteSyncJobService
        // (voir SyncJob::entityAssignmentStrategy()) - ce writer ne
        // fait qu'appliquer la valeur reçue, jamais la calculer.
        // null signifie "aucune stratégie configurée pour ce SyncJob",
        // pas "entité 0" : entities_id n'est alors pas explicitement
        // fixé, laissant GLPI appliquer son propre comportement par
        // défaut (adapté à une instance mono-entité).
        if ($context->entitiesId !== null) {
            if (!$this->entityExists($context->entitiesId)) {
                return false;
            }

            $data['entities_id'] = $context->entitiesId;
        }

        $existingLink = $this->objectLinkRepository->findByExternalId(
            $context->authConfigReference,
            $context->externalObjectType,
            $context->externalId,
        );

        if ($existingLink !== null) {
            // A matching external identifier may have been linked by a
            // different interaction targeting another GLPI itemtype.
            // Never write to a row of an unintended object type.
            if ($existingLink->itemtype() !== $context->itemtype) {
                throw new \RuntimeException('ObjectLink target type conflicts with the configured interaction target.');
            }
            // An ObjectLink can outlive the GLPI target if an administrator
            // purges that asset/user directly in GLPI. Keeping the stale link
            // forever would make every future synchronization fail without a
            // chance to reconcile again. Drop only this bookkeeping link when
            // the target no longer exists, then safely restart the first-time
            // reconciliation path.
            if (!$item->getFromDB($existingLink->itemId())) {
                if ($existingLink->id() !== null) {
                    $this->objectLinkRepository->delete($existingLink->id());
                }

                return $this->writeAndEnrich($item, $context, $data, $fieldsWriter, $fieldsValues, $email);
            }

            $data['id'] = $existingLink->itemId();
            $this->lastAction = 'update';
            $success = $this->updateExisting(
                $item,
                $data,
                $context->replaceLinkedTicketStatus && $context->itemtype === TargetItemtype::TICKET
            );

            if ($success) {
                $existingLink->touch();
                $this->objectLinkRepository->save($existingLink);
            }

            if ($success) {
                $this->writeFieldsValues($context, $fieldsWriter, $fieldsValues);
                $this->writeUserEmail($context, $email);
            }
            return $success;
        }

        return $this->writeAndEnrich($item, $context, $data, $fieldsWriter, $fieldsValues, $email);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $fieldsValues */
    private function writeAndEnrich(
        \CommonDBTM $item,
        ReconciliationContext $context,
        array $data,
        ?GlpiTargetFieldsWriter $fieldsWriter,
        array $fieldsValues,
        ?string $email = null
    ): bool {
        $success = $this->reconcileForTheFirstTime($item, $context, $data);
        if ($success) {
            $this->writeFieldsValues($context, $fieldsWriter, $fieldsValues);
            $this->writeUserEmail($context, $email);
        }
        return $success;
    }

    /** @param array<string,mixed> $data */
    private function updateExisting(\CommonDBTM $item, array $data, bool $replaceTicketStatus = false): bool
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0 || !$item->getFromDB($id) || (int) ($item->fields['is_deleted'] ?? 0) !== 0) {
            return false;
        }
        $delta = \GlpiPlugin\Uxconnector\Domain\Sync\Policy\ExistingValuePolicy::additions($item->fields, $data);
        if ($replaceTicketStatus) {
            $status = $data['status'] ?? null;
            unset($delta['status']);
            if (
                (is_int($status) || is_string($status))
                && in_array((string) $status, ['1', '2', '3', '4', '5', '6'], true)
                && (int) ($item->fields['status'] ?? 0) !== (int) $status
            ) {
                $delta['status'] = (int) $status;
            }
        }
        if ($delta === []) {
            $this->lastAction = 'skip';
            return true;
        }
        return SyncRuntimeGuard::run(static fn(): bool => (bool) $item->update(['id' => $id] + $delta));
    }

    private function writeUserEmail(ReconciliationContext $context, ?string $email): void
    {
        if ($email === null) {
            return;
        }
        $link = $this->objectLinkRepository->findByExternalId(
            $context->authConfigReference,
            $context->externalObjectType,
            $context->externalId,
        );
        if ($link === null || $link->itemtype() !== TargetItemtype::USER) {
            throw new \RuntimeException('Target user ObjectLink is unavailable after GLPI item write.');
        }
        if (
            (new GlpiUserEmailWriter($this->db))->write($link->itemId(), $email)
            && $this->lastAction === 'skip'
        ) {
            $this->lastAction = 'update';
        }
    }

    /** @param array<string, mixed> $values */
    private function writeFieldsValues(
        ReconciliationContext $context,
        ?GlpiTargetFieldsWriter $writer,
        array $values
    ): void {
        if ($writer === null || $values === []) {
            return;
        }
        $link = $this->objectLinkRepository->findByExternalId(
            $context->authConfigReference,
            $context->externalObjectType,
            $context->externalId,
        );
        if ($link === null || $link->itemtype() !== $context->itemtype) {
            throw new \RuntimeException('Target ObjectLink is unavailable after GLPI item write.');
        }
        if (
            $writer->write(ltrim($context->itemtype->glpiClassName(), '\\'), $link->itemId(), $values)
            && $this->lastAction === 'skip'
        ) {
            $this->lastAction = 'update';
        }
    }

    /**
     * SIM links are local GLPI 11 references, never numeric source IDs.
     * Reject unresolvable relations rather than silently linking a SIM
     * to another client's equipment or line.
     * @param array<string, mixed> $data
     */
    private function validateSimCardReferences(array $data, ?int $entitiesId): bool
    {
        foreach (
            ['devicesimcards_id' => 'glpi_devicesimcards', 'lines_id' => 'glpi_lines',
                'locations_id' => 'glpi_locations', 'users_id' => 'glpi_users',
                'states_id' => 'glpi_states'] as $field => $table
        ) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if (filter_var($data[$field], FILTER_VALIDATE_INT) === false || (int) $data[$field] < 0) {
                return false;
            }
            $id = (int) $data[$field];
            if ($id > 0 && !$this->referenceExists($table, $id, $entitiesId)) {
                return false;
            }
        }
        // GLPI requires a catalog component for predictable SIM inventory.
        if (!isset($data['devicesimcards_id']) || (int) $data['devicesimcards_id'] <= 0) {
            return false;
        }
        $hasType = isset($data['itemtype']) && $data['itemtype'] !== '';
        $hasItem = isset($data['items_id']) && (int) $data['items_id'] !== 0;
        if ($hasType !== $hasItem) {
            return false;
        }
        if ($hasType) {
            $table = match ($data['itemtype']) {
                'Phone' => 'glpi_phones',
                'NetworkEquipment' => 'glpi_networkequipments',
                'Computer' => 'glpi_computers',
                default => null,
            };
            if (
                $table === null || filter_var(
                    $data['items_id'],
                    FILTER_VALIDATE_INT
                ) === false || (int) $data['items_id'] <= 0
            ) {
                return false;
            }
            return $this->referenceExists($table, (int) $data['items_id'], $entitiesId);
        }
        return true;
    }

    private function referenceExists(string $table, int $id, ?int $entitiesId): bool
    {
        $where = ['id' => $id];
        if ($entitiesId !== null && !in_array($table, ['glpi_states'], true)) {
            $where['entities_id'] = $entitiesId;
        }
        foreach ($this->db->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1]) as $row) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $data Mapped fields, "id" already stripped,
     *        "entities_id" already applied if resolved.
     */
    private function reconcileForTheFirstTime(\CommonDBTM $item, ReconciliationContext $context, array $data): bool
    {
        if ($context->matchField->value() === 'asset_identity') {
            return $this->reconcileAssetIdentity($item, $context, $data);
        }

        $matchValue = $data[$context->matchField->value()] ?? null;

        if ($matchValue === null || $matchValue === '') {
            // Cannot even attempt the first reconciliation without a
            // match value: refusing to guess is safer than risking a
            // duplicate or a wrong update.
            return false;
        }

        // CORRECTIF (gestion des entités, revue de code externe) :
        // scoper également la recherche par entities_id quand elle est
        // connue réduit directement le risque de collision - deux
        // enregistrements de même MatchField dans deux entités
        // différentes (l'exemple même utilisé par la revue : deux
        // Computer de même serial, un à Nantes, un à Paris) ne sont
        // plus ambigus dès lors que l'entité cible est déterminée.
        $matchingIds = $this->findMatchingIds(
            $item->getTable(),
            $context->matchField->value(),
            $matchValue,
            $context->entitiesId,
        );

        if (count($matchingIds) > 1) {
            // COLLISION : plusieurs enregistrements GLPI partagent la
            // même valeur de MatchField - impossible de savoir lequel
            // est le bon sans risquer de modifier l'autre. Refuser
            // plutôt que deviner.
            return false;
        }

        if (count($matchingIds) === 1) {
            // LINK : un enregistrement GLPI existe déjà (créé
            // manuellement, ou par un import antérieur à ce plugin) -
            // on l'adopte plutôt que d'en créer un doublon.
            $existingId = $matchingIds[0];
            $data['id'] = $existingId;
            $this->lastAction = 'link';
            $success = $this->updateExisting($item, $data);
        } else {
            // CREATE : aucun enregistrement ne correspond.
            $this->lastAction = 'create';
            $generatedId = SyncRuntimeGuard::run(static fn() => $item->add($data));
            $success = $generatedId !== false;
            $existingId = $success ? (int) $generatedId : null;
        }

        if ($success && $existingId !== null) {
            $link = ObjectLink::create(
                $context->syncJobId,
                $context->authConfigReference,
                $context->externalId,
                $context->itemtype,
                $existingId,
                $context->externalObjectType,
            );
            $this->objectLinkRepository->save($link);
        }

        return $success;
    }

    /**
     * GLPI-to-GLPI asset reconciliation.
     *
     * Source numeric IDs are stable only inside one remote GLPI and are already
     * preserved by ObjectLink. For the first encounter, use asset identity
     * fields instead of adopting by an arbitrary local primary key.
     *
     * Computers and network equipment may safely fall back to their hostname
     * when UUID/serial/inventory number are unavailable. For monitors, phones,
     * peripherals and printers, a shared display name is too ambiguous, so name
     * is used only as data for a new record, never as a cross-source match key.
     *
     * @param array<string, mixed> $data
     */
    private function reconcileAssetIdentity(\CommonDBTM $item, ReconciliationContext $context, array $data): bool
    {
        $allIdentityFields = ['uuid', 'serial', 'otherserial', 'name'];
        $hasAnyIdentity = false;
        foreach ($allIdentityFields as $field) {
            if ($this->hasUsableIdentityValue($data[$field] ?? null)) {
                $hasAnyIdentity = true;
                break;
            }
        }

        if (!$hasAnyIdentity) {
            return false;
        }

        $matchFields = match ($context->itemtype) {
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::COMPUTER,
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::NETWORK_EQUIPMENT => ['uuid', 'serial',
                'otherserial', 'name'],
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::MONITOR,
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::PERIPHERAL,
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::PHONE,
            \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::PRINTER => ['uuid', 'serial', 'otherserial'],
            default => [],
        };

        foreach ($matchFields as $field) {
            $value = $data[$field] ?? null;
            if (!$this->hasUsableIdentityValue($value)) {
                continue;
            }

            $matchingIds = $this->findMatchingIds(
                $item->getTable(),
                $field,
                is_string($value) ? trim($value) : $value,
                $context->entitiesId,
            );

            if (count($matchingIds) > 1) {
                return false;
            }

            if (count($matchingIds) === 1) {
                $existingId = $matchingIds[0];
                $data['id'] = $existingId;
                $this->lastAction = 'link';
                $success = $this->updateExisting($item, $data);
                if ($success) {
                    $this->saveObjectLink($context, $existingId);
                }

                return $success;
            }
        }

        $this->lastAction = 'create';
        $generatedId = SyncRuntimeGuard::run(static fn() => $item->add($data));
        if ($generatedId === false) {
            return false;
        }

        $this->saveObjectLink($context, (int) $generatedId);

        return true;
    }

    private function hasUsableIdentityValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== 0 && $value !== false;
    }

    private function saveObjectLink(ReconciliationContext $context, int $itemId): void
    {
        $link = ObjectLink::create(
            $context->syncJobId,
            $context->authConfigReference,
            $context->externalId,
            $context->itemtype,
            $itemId,
            $context->externalObjectType,
        );
        $this->objectLinkRepository->save($link);
    }

    private function entityExists(int $entitiesId): bool
    {
        if ($entitiesId < 0) {
            return false;
        }

        $entity = new \Entity();

        return $entity->getFromDB($entitiesId);
    }

    /**
     * @return list<int>
     */
    private function findMatchingIds(string $table, string $matchField, mixed $matchValue, ?int $entitiesId): array
    {
        $where = [$matchField => $matchValue];

        if ($entitiesId !== null) {
            $where['entities_id'] = $entitiesId;
        }

        $result = $this->db->request([
            'SELECT' => 'id',
            'FROM'   => $table,
            'WHERE'  => $where,
        ]);

        $ids = [];

        foreach ($result as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }
}
