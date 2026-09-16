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
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ReconciliationContext;
use GlpiPlugin\Uxconnector\Domain\Sync\Writer\SyncItemWriterInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime\SyncRuntimeGuard;

/**
 * Writes one mapped record into a GLPI itemtype (Computer, User, ...)
 * via that itemtype's own CommonDBTM class — never raw SQL INSERT/UPDATE
 * — so every GLPI-side behaviour a plugin is expected to respect
 * (hooks, logs) still runs normally.
 *
 * `CommonDBTM::add()`/`update()` do not themselves enforce the
 * interactive-user rights model (`can()`/`canCreateItem()`) — that
 * check is normally the calling Controller's responsibility. This
 * writer is deliberately never gated by it: UXConnector's CronTask
 * (and its "Run now" UI counterpart) acts as an explicit **system
 * actor** with its own authorization model — the plugin's own
 * `plugin_uxconnector_syncjob` right, checked once before a SyncJob
 * runs at all — not as a stand-in for an interactive GLPI user. An
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
 * of TargetItemtype's fixed, curated cases — never a string derived
 * from the external system's response or from unvalidated
 * configuration — so the `new $className()` below can never
 * instantiate an arbitrary class (see TargetItemtype's doc-block).
 */
final class GlpiItemWriter implements SyncItemWriterInterface
{
   public function __construct(
        private readonly DBmysql $db,
        private readonly ObjectLinkRepositoryInterface $objectLinkRepository
    ) {
   }

   public function write(ReconciliationContext $context, array $mappedFields): bool {
       $item = match ($context->itemtype) {
           \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::COMPUTER => new \Computer(),
           \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::MONITOR => new \Monitor(),
           \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::NETWORK_EQUIPMENT => new \NetworkEquipment(),
           \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::PERIPHERAL => new \Peripheral(),
           \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::PHONE => new \Phone(),
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
         $data = $mappedFields;
      foreach (['id', 'entities_id', 'is_deleted', 'is_template', 'template_name', 'date_mod', 'date_creation'] as $reservedField) {
         unset($data[$reservedField]);
      }

      // Safe bootstrap defaults for reference data created into an empty GLPI.
      // They can still be overridden later by the target configuration.
      if ($context->itemtype === \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::ENTITY
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
         // (voir SyncJob::entityAssignmentStrategy()) — ce writer ne
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
           $context->externalId
       );

      if ($existingLink !== null) {
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

            return $this->reconcileForTheFirstTime($item, $context, $data);
         }

          $data['id'] = $existingLink->itemId();
          $success = SyncRuntimeGuard::run(static fn (): bool => (bool) $item->update($data));

         if ($success) {
             $existingLink->touch();
             $this->objectLinkRepository->save($existingLink);
         }

          return $success;
      }

       return $this->reconcileForTheFirstTime($item, $context, $data);
   }

    /**
     * @param array<string, mixed> $data Mapped fields, "id" already stripped,
     *        "entities_id" already applied if resolved.
     */
   private function reconcileForTheFirstTime(\CommonDBTM $item, ReconciliationContext $context, array $data): bool {
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
       // connue réduit directement le risque de collision — deux
       // enregistrements de même MatchField dans deux entités
       // différentes (l'exemple même utilisé par la revue : deux
       // Computer de même serial, un à Nantes, un à Paris) ne sont
       // plus ambigus dès lors que l'entité cible est déterminée.
       $matchingIds = $this->findMatchingIds(
           $item->getTable(),
           $context->matchField->value(),
           $matchValue,
           $context->entitiesId
       );

      if (count($matchingIds) > 1) {
          // COLLISION : plusieurs enregistrements GLPI partagent la
          // même valeur de MatchField — impossible de savoir lequel
          // est le bon sans risquer de modifier l'autre. Refuser
          // plutôt que deviner.
          return false;
      }

      if (count($matchingIds) === 1) {
          // LINK : un enregistrement GLPI existe déjà (créé
          // manuellement, ou par un import antérieur à ce plugin) —
          // on l'adopte plutôt que d'en créer un doublon.
          $existingId = $matchingIds[0];
          $data['id'] = $existingId;
          $success = SyncRuntimeGuard::run(static fn (): bool => (bool) $item->update($data));
      } else {
          // CREATE : aucun enregistrement ne correspond.
          $generatedId = SyncRuntimeGuard::run(static fn () => $item->add($data));
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
              $context->externalObjectType
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
   private function reconcileAssetIdentity(\CommonDBTM $item, ReconciliationContext $context, array $data): bool {
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
         \GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype::NETWORK_EQUIPMENT => ['uuid', 'serial', 'otherserial', 'name'],
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
            $context->entitiesId
         );

         if (count($matchingIds) > 1) {
            return false;
         }

         if (count($matchingIds) === 1) {
            $existingId = $matchingIds[0];
            $data['id'] = $existingId;
            $success = SyncRuntimeGuard::run(static fn (): bool => (bool) $item->update($data));
            if ($success) {
               $this->saveObjectLink($context, $existingId);
            }

            return $success;
         }
      }

      $generatedId = SyncRuntimeGuard::run(static fn () => $item->add($data));
      if ($generatedId === false) {
         return false;
      }

      $this->saveObjectLink($context, (int) $generatedId);

      return true;
   }

   private function hasUsableIdentityValue(mixed $value): bool {
      if ($value === null) {
         return false;
      }

      if (is_string($value)) {
         return trim($value) !== '';
      }

      return $value !== 0 && $value !== false;
   }

   private function saveObjectLink(ReconciliationContext $context, int $itemId): void {
      $link = ObjectLink::create(
         $context->syncJobId,
         $context->authConfigReference,
         $context->externalId,
         $context->itemtype,
         $itemId,
         $context->externalObjectType
      );
      $this->objectLinkRepository->save($link);
   }

   private function entityExists(int $entitiesId): bool {
      if ($entitiesId < 0) {
          return false;
      }

       $entity = new \Entity();

       return $entity->getFromDB($entitiesId);
   }

    /**
     * @return list<int>
     */
   private function findMatchingIds(string $table, string $matchField, mixed $matchValue, ?int $entitiesId): array {
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
