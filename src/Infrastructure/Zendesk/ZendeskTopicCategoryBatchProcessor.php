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

use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\InboundBatchProcessorInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/**
 * Scans the non-closed Zendesk ticket stream and materializes only the topic
 * categories actually used by the migration scope.
 */
final class ZendeskTopicCategoryBatchProcessor implements InboundBatchProcessorInterface
{
   private const TEMPLATE_ID = 'zendesk.itilcategories.bootstrap';

   public function __construct(
      private readonly ZendeskTopicCatalog $catalog,
      private readonly ZendeskTopicCategoryService $categories
   ) {
   }

   public function supports(SyncJob $syncJob): bool {
      return $syncJob->templateId() === self::TEMPLATE_ID;
   }

   public function process(SyncJob $syncJob, array $items): array {
      $topicValues = [];
      foreach ($items as $item) {
         if (!is_array($item)) {
            continue;
         }

         $status = strtolower(trim((string) ($item['status'] ?? '')));
         if (!in_array($status, ['new', 'open', 'pending', 'hold', 'solved'], true)) {
            continue;
         }

         $topicValue = $this->catalog->topicValueFromTicket($syncJob, $item);
         if ($topicValue === null) {
            continue;
         }
         $topicValues[$topicValue] = true;
      }

      $processed = 0;
      $failed = 0;
      foreach (array_keys($topicValues) as $topicValue) {
         $label = $this->catalog->labelForValue($syncJob, $topicValue);
         if ($label === null) {
            $failed++;
            continue;
         }

         if ($this->categories->ensureForTopic($syncJob, $topicValue, $label) === null) {
            $failed++;
            continue;
         }

         $processed++;
      }

      return [$processed, $failed];
   }
}
