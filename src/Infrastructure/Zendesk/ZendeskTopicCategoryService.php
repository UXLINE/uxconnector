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

namespace GlpiPlugin\Uxconnector\Infrastructure\Zendesk;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;

/** Builds/reconciles the GLPI ITIL category hierarchy from Zendesk topic labels. */
final class ZendeskTopicCategoryService
{
   public function __construct(private readonly ObjectLinkRepositoryInterface $objectLinks) {
   }

   public function ensureForTopic(SyncJob $syncJob, string $topicValue, string $label): ?int {
      $topicValue = trim($topicValue);
      $label = trim($label);
      if ($topicValue === '' || $label === '') {
         return null;
      }

      $existingTopicLink = $this->objectLinks->findByExternalId(
         $syncJob->authConfigReference(),
         'zendesk_topic',
         new ExternalId($topicValue)
      );
      if ($existingTopicLink !== null && $existingTopicLink->itemtype() === TargetItemtype::ITIL_CATEGORY) {
         $category = new \ITILCategory();
         if ($category->getFromDB($existingTopicLink->itemId())) {
            return $existingTopicLink->itemId();
         }
         if ($existingTopicLink->id() !== null) {
            $this->objectLinks->delete($existingTopicLink->id());
         }
      }

      $segments = preg_split('/\s*::\s*/', $label) ?: [];
      $segments = array_values(array_filter(array_map('trim', $segments), static fn (string $segment): bool => $segment !== ''));
      if ($segments === []) {
         return null;
      }

      $parentId = 0;
      $path = [];
      $leafId = null;

      foreach ($segments as $index => $segment) {
         $path[] = $segment;
         $isLeaf = $index === array_key_last($segments);
         $externalType = $isLeaf ? 'zendesk_topic' : 'zendesk_topic_path';
         $externalId = $isLeaf
            ? $topicValue
            : 'path:' . sha1(implode('::', $path));

         $categoryId = $this->resolveLinkedCategory($syncJob, $externalType, $externalId);
         if ($categoryId === null) {
            $categoryId = $this->findOrCreateCategory($segment, $parentId);
            if ($categoryId === null) {
               return null;
            }
            if (!$this->linkCategory($syncJob, $externalType, $externalId, $categoryId)) {
               return null;
            }
         } else {
            $category = new \ITILCategory();
            if (!$category->getFromDB($categoryId)) {
               return null;
            }
            $currentParent = (int) ($category->fields['itilcategories_id'] ?? 0);
            $currentName = (string) ($category->fields['name'] ?? '');
            if ($currentParent !== $parentId || $currentName !== $segment) {
               if (!$category->update([
                  'id' => $categoryId,
                  'name' => $segment,
                  'itilcategories_id' => $parentId,
               ])) {
                  return null;
               }
            }
         }

         $parentId = $categoryId;
         if ($isLeaf) {
            $leafId = $categoryId;
         }
      }

      return $leafId;
   }

   private function resolveLinkedCategory(SyncJob $syncJob, string $externalType, string $externalId): ?int {
      $link = $this->objectLinks->findByExternalId(
         $syncJob->authConfigReference(),
         $externalType,
         new ExternalId($externalId)
      );
      if ($link === null || $link->itemtype() !== TargetItemtype::ITIL_CATEGORY) {
         return null;
      }

      $category = new \ITILCategory();
      if (!$category->getFromDB($link->itemId())) {
         if ($link->id() !== null) {
            $this->objectLinks->delete($link->id());
         }
         return null;
      }

      return $link->itemId();
   }

   private function findOrCreateCategory(string $name, int $parentId): ?int {
      $model = new \ITILCategory();
      $matches = $model->find([
         'name' => $name,
         'itilcategories_id' => $parentId,
         'entities_id' => 0,
      ], ['id ASC'], 2);

      if (count($matches) > 1) {
         // Ambiguous pre-existing taxonomy: do not silently link the wrong node.
         return null;
      }
      if ($matches !== []) {
         return (int) array_key_first($matches);
      }

      $generatedId = $model->add([
         'name' => $name,
         'itilcategories_id' => $parentId,
         'entities_id' => 0,
         'is_recursive' => 1,
         'is_helpdeskvisible' => 1,
         'is_incident' => 1,
         'is_request' => 1,
      ]);

      return $generatedId === false ? null : (int) $generatedId;
   }

   private function linkCategory(
      SyncJob $syncJob,
      string $externalType,
      string $externalId,
      int $categoryId
   ): bool {
      $syncJobId = $syncJob->id();
      if ($syncJobId === null) {
         return false;
      }

      $this->objectLinks->save(ObjectLink::create(
         $syncJobId,
         $syncJob->authConfigReference(),
         new ExternalId($externalId),
         TargetItemtype::ITIL_CATEGORY,
         $categoryId,
         $externalType
      ));

      return true;
   }
}
