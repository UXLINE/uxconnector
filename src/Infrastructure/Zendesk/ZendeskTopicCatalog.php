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

use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/**
 * Discovers the Zendesk "Sujet" / topic tagger field without hard-coding a
 * customer-specific field id, then exposes its technical-value -> label map.
 *
 * The field is identified by tagger options whose value starts with `topic__`.
 * This makes the discovery independent from the Zendesk UI language and from
 * the numeric custom-field id used by a particular tenant.
 */
final class ZendeskTopicCatalog
{
   private const MAX_FIELD_PAGES = 20;

   /**
    * @var array<int, array{field_id:string, labels:array<string,string>}>
    */
   private array $cache = [];

   public function __construct(
      private readonly AuthConfigRepositoryInterface $authConfigs,
      private readonly HttpEngineInterface $httpEngine
   ) {
   }

   /**
    * @return array{field_id:string, labels:array<string,string>}|null
    */
   public function forJob(SyncJob $syncJob): ?array {
      $reference = $syncJob->authConfigReference()->value();
      if (isset($this->cache[$reference])) {
         return $this->cache[$reference];
      }

      $authConfig = $this->authConfigs->findById(new AuthConfigId($reference));
      if ($authConfig === null) {
         return null;
      }

      $path = '/api/v2/ticket_fields.json?per_page=100';
      $candidates = [];
      $page = 0;

      while ($path !== '') {
         $page++;
         if ($page > self::MAX_FIELD_PAGES) {
            return null;
         }

         $response = $this->httpEngine->execute($authConfig, new HttpRequest(HttpMethod::GET, $path));
         if (!$response->isSuccessful()) {
            return null;
         }

         $payload = $response->json();
         $fields = $payload['ticket_fields'] ?? null;
         if (!is_array($fields)) {
            return null;
         }

         foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['id'])) {
               continue;
            }
            if (strtolower((string) ($field['type'] ?? '')) !== 'tagger') {
               continue;
            }

            $options = $field['custom_field_options'] ?? null;
            if (!is_array($options)) {
               continue;
            }

            $labels = [];
            foreach ($options as $option) {
               if (!is_array($option)) {
                  continue;
               }
               $value = trim((string) ($option['value'] ?? ''));
               $name = trim((string) ($option['name'] ?? ''));
               if ($value === '' || $name === '' || !str_starts_with($value, 'topic__')) {
                  continue;
               }
               $labels[$value] = $name;
            }

            if ($labels !== []) {
               $candidates[] = [
                  'field_id' => (string) $field['id'],
                  'title' => strtolower(trim((string) ($field['title'] ?? ''))),
                  'labels' => $labels,
               ];
            }
         }

         $nextPage = $payload['next_page'] ?? null;
         $path = is_string($nextPage) && $nextPage !== ''
            ? $this->relativePath($nextPage)
            : '';
      }

      if ($candidates === []) {
         return null;
      }

      $selected = null;
      if (count($candidates) === 1) {
         $selected = $candidates[0];
      } else {
         foreach ($candidates as $candidate) {
            if (in_array($candidate['title'], ['sujet', 'subject', 'topic', 'intent'], true)) {
               $selected = $candidate;
               break;
            }
         }
      }

      if ($selected === null) {
         // Ambiguous tenant configuration: refusing to guess prevents mapping
         // a confidence/sentiment tagger as an ITIL category source.
         return null;
      }

      $catalog = [
         'field_id' => $selected['field_id'],
         'labels' => $selected['labels'],
      ];
      $this->cache[$reference] = $catalog;

      return $catalog;
   }

   /** @param array<string, mixed> $ticket */
   public function topicValueFromTicket(SyncJob $syncJob, array $ticket): ?string {
      $catalog = $this->forJob($syncJob);
      if ($catalog === null) {
         return null;
      }

      $customFields = $ticket['custom_fields'] ?? null;
      if (!is_array($customFields)) {
         return null;
      }

      foreach ($customFields as $customField) {
         if (!is_array($customField)) {
            continue;
         }
         if ((string) ($customField['id'] ?? '') !== $catalog['field_id']) {
            continue;
         }

         $value = $customField['value'] ?? null;
         if (!is_scalar($value)) {
            return null;
         }

         $topic = trim((string) $value);
         return $topic !== '' ? $topic : null;
      }

      return null;
   }

   public function labelForValue(SyncJob $syncJob, string $value): ?string {
      $catalog = $this->forJob($syncJob);
      if ($catalog === null) {
         return null;
      }

      return $catalog['labels'][$value] ?? null;
   }

   private function relativePath(string $url): string {
      $parts = parse_url($url);
      if ($parts === false || !isset($parts['path'])) {
         return '';
      }

      $path = (string) $parts['path'];
      if (isset($parts['query']) && $parts['query'] !== '') {
         $path .= '?' . $parts['query'];
      }

      return $path;
   }
}
