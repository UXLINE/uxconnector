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

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\InboundRecordPostProcessorInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;

/**
 * Rebuilds Zendesk ticket relations and conversational history that cannot be
 * expressed as scalar field mappings: requester, assignee, assigned group,
 * entity, ITIL category, followups and comment attachments/screenshots.
 *
 * Important migration rule: the first Zendesk comment is usually the same text
 * as Ticket.description. In that case the followup itself is deliberately not
 * duplicated, but its attachments are still imported and linked to the ticket.
 */
final class ZendeskTicketPostProcessor implements InboundRecordPostProcessorInterface
{
   private const MAX_COMMENT_PAGES = 1000;
   private const MAX_REDIRECTS = 3;
   private const MAX_ATTACHMENT_BYTES = 50 * 1024 * 1024;
   private const DOWNLOAD_CHUNK_BYTES = 8192;
   private const DOWNLOAD_TIMEOUT_SECONDS = 60;

   /** @var list<string> */
   private const SUPPORTED_TEMPLATES = [
      'zendesk.tickets.nonclosed.inbound',
      'zendesk.tickets.delta.inbound',
      'zendesk.tickets.active.inbound',
      'zendesk.tickets.inbound',
   ];

   public function __construct(
      private readonly ObjectLinkRepositoryInterface $objectLinks,
      private readonly AuthConfigRepositoryInterface $authConfigs,
      private readonly HttpEngineInterface $httpEngine,
      private readonly DestinationPolicyInterface $destinationPolicy,
      private readonly ZendeskTopicCatalog $topicCatalog,
      private readonly ZendeskTopicCategoryService $topicCategories
   ) {
   }

   public function supports(SyncJob $syncJob): bool {
      return in_array($syncJob->templateId(), self::SUPPORTED_TEMPLATES, true);
   }

   public function process(SyncJob $syncJob, array $sourceRecord, ExternalId $externalId): bool {
      $ticketLink = $this->objectLinks->findByExternalId(
         $syncJob->authConfigReference(),
         $syncJob->externalObjectType(),
         $externalId
      );
      if ($ticketLink === null || $ticketLink->itemtype() !== TargetItemtype::TICKET) {
         return false;
      }

      $ticketId = $ticketLink->itemId();
      if (!$this->applyEntityAndCategory($syncJob, $sourceRecord, $ticketId)) {
         return false;
      }
      if (!$this->applyActors($syncJob, $sourceRecord, $ticketId)) {
         return false;
      }

      return $this->importComments($syncJob, $sourceRecord, $ticketId, $externalId);
   }

   /** @param array<string, mixed> $sourceRecord */
   private function applyEntityAndCategory(SyncJob $syncJob, array $sourceRecord, int $ticketId): bool {
      $update = ['id' => $ticketId];

      if (isset($sourceRecord['organization_id']) && is_scalar($sourceRecord['organization_id'])) {
         $entityId = $this->resolveMappedId(
            $syncJob,
            'zendesk_organization',
            (string) $sourceRecord['organization_id'],
            TargetItemtype::ENTITY
         );
         if ($entityId === null) {
            return false;
         }
         $update['entities_id'] = $entityId;
      }

      $topicValue = $this->topicCatalog->topicValueFromTicket($syncJob, $sourceRecord);
      if ($topicValue !== null) {
         $categoryId = $this->resolveMappedId(
            $syncJob,
            'zendesk_topic',
            $topicValue,
            TargetItemtype::ITIL_CATEGORY
         );

         // Delta safety: if a new Zendesk topic appears after the dedicated
         // category bootstrap job, create its hierarchy on demand instead of
         // leaving the new ticket uncategorized.
         if ($categoryId === null) {
            $label = $this->topicCatalog->labelForValue($syncJob, $topicValue);
            if ($label === null) {
               return false;
            }
            $categoryId = $this->topicCategories->ensureForTopic($syncJob, $topicValue, $label);
         }

         if ($categoryId === null) {
            return false;
         }
         $update['itilcategories_id'] = $categoryId;
      }

      if (count($update) === 1) {
         return true;
      }

      return (bool) (new \Ticket())->update($update);
   }

   /** @param array<string, mixed> $sourceRecord */
   private function applyActors(SyncJob $syncJob, array $sourceRecord, int $ticketId): bool {
      if (isset($sourceRecord['requester_id']) && is_scalar($sourceRecord['requester_id'])) {
         $userId = $this->resolveMappedId(
            $syncJob,
            'zendesk_user',
            (string) $sourceRecord['requester_id'],
            TargetItemtype::USER
         );
         if ($userId === null || !$this->ensureTicketUser($ticketId, $userId, $this->requesterType())) {
            return false;
         }
      }

      if (
         isset($sourceRecord['assignee_id'])
         && is_scalar($sourceRecord['assignee_id'])
         && (string) $sourceRecord['assignee_id'] !== ''
      ) {
         $userId = $this->resolveMappedId(
            $syncJob,
            'zendesk_user',
            (string) $sourceRecord['assignee_id'],
            TargetItemtype::USER
         );
         if ($userId === null || !$this->ensureTicketUser($ticketId, $userId, $this->assignType())) {
            return false;
         }
      }

      if (
         isset($sourceRecord['group_id'])
         && is_scalar($sourceRecord['group_id'])
         && (string) $sourceRecord['group_id'] !== ''
      ) {
         $groupId = $this->resolveMappedId(
            $syncJob,
            'zendesk_group',
            (string) $sourceRecord['group_id'],
            TargetItemtype::GROUP
         );
         if ($groupId === null || !$this->ensureTicketGroup($ticketId, $groupId, $this->assignType())) {
            return false;
         }
      }

      return true;
   }

   private function resolveMappedId(
      SyncJob $syncJob,
      string $externalType,
      string $externalId,
      TargetItemtype $expectedType
   ): ?int {
      $link = $this->objectLinks->findByExternalId(
         $syncJob->authConfigReference(),
         $externalType,
         new ExternalId($externalId)
      );
      if ($link === null || $link->itemtype() !== $expectedType) {
         return null;
      }

      return $link->itemId();
   }

   private function ensureTicketUser(int $ticketId, int $userId, int $type): bool {
      $model = new \Ticket_User();
      $existing = $model->find([
         'tickets_id' => $ticketId,
         'users_id' => $userId,
         'type' => $type,
      ], [], 1);
      if ($existing !== []) {
         return true;
      }

      return $model->add([
         'tickets_id' => $ticketId,
         'users_id' => $userId,
         'type' => $type,
         'use_notification' => 0,
      ]) !== false;
   }

   private function ensureTicketGroup(int $ticketId, int $groupId, int $type): bool {
      $model = new \Group_Ticket();
      $existing = $model->find([
         'tickets_id' => $ticketId,
         'groups_id' => $groupId,
         'type' => $type,
      ], [], 1);
      if ($existing !== []) {
         return true;
      }

      return $model->add([
         'tickets_id' => $ticketId,
         'groups_id' => $groupId,
         'type' => $type,
      ]) !== false;
   }

   /** @param array<string, mixed> $sourceRecord */
   private function importComments(
      SyncJob $syncJob,
      array $sourceRecord,
      int $ticketId,
      ExternalId $ticketExternalId
   ): bool {
      $authConfig = $this->authConfigs->findById(new AuthConfigId($syncJob->authConfigReference()->value()));
      if ($authConfig === null) {
         return false;
      }

      $ticketIdZendesk = rawurlencode($ticketExternalId->value());
      $path = sprintf(
         '/api/v2/tickets/%s/comments.json?per_page=100&include=users&include_inline_images=true',
         $ticketIdZendesk
      );
      $page = 0;
      $commentIndex = 0;
      $description = $this->normalizeText((string) ($sourceRecord['description'] ?? ''));

      while ($path !== '') {
         $page++;
         if ($page > self::MAX_COMMENT_PAGES) {
            return false;
         }

         $response = $this->httpEngine->execute($authConfig, new HttpRequest(HttpMethod::GET, $path));
         if (!$response->isSuccessful()) {
            return false;
         }

         $payload = $response->json();
         $comments = $payload['comments'] ?? null;
         if (!is_array($comments)) {
            return false;
         }

         foreach ($comments as $comment) {
            if (!is_array($comment) || !isset($comment['id'])) {
               return false;
            }

            $commentExternalId = new ExternalId((string) $comment['id']);
            // Zendesk "body" keeps the Markdown references used by inline images.
            // plain_body is still useful as a fallback but must not be copied raw
            // because GLPI does not render Zendesk Markdown image syntax.
            $body = (string) ($comment['body'] ?? $comment['plain_body'] ?? '');
            $date = $this->toGlpiDate((string) ($comment['created_at'] ?? ''));
            $attachments = isset($comment['attachments']) && is_array($comment['attachments'])
               ? $comment['attachments']
               : [];
            $attachments = $this->mergeInlineAttachmentsFromCommentBodies(
               $attachments,
               $body,
               (string) ($comment['html_body'] ?? '')
            );

            $isInitialDuplicate = $commentIndex === 0
               && $description !== ''
               && $this->normalizeText($this->bodyWithoutInlineImages($body)) === $description;
            $commentIndex++;

            if ($isInitialDuplicate) {
               // Keep Ticket.content as the initial message, but let GLPI's
               // native addFiles() pipeline create/link inline screenshots.
               // This is the same mechanism used by the rich-text editor and
               // avoids persisting Zendesk URLs or hand-crafted <img> tags.
               $ticket = new \Ticket();
               if (!$ticket->getFromDB($ticketId)) {
                  return false;
               }
               if (!$this->syncNativeAttachments(
                  $syncJob,
                  $authConfig,
                  $attachments,
                  $ticket,
                  $body,
                  0,
                  $date
               )) {
                  return false;
               }
               continue;
            }

            $existingCommentLink = $this->objectLinks->findByExternalId(
               $syncJob->authConfigReference(),
               'zendesk_comment',
               $commentExternalId
            );

            $authorId = isset($comment['author_id']) && is_scalar($comment['author_id'])
               ? (string) $comment['author_id']
               : '';

            // System-generated events are not conversational followups. Their
            // attachments, if any, are still preserved on the ticket.
            if ($authorId === '' || $authorId === '-1') {
               $ticket = new \Ticket();
               if (!$ticket->getFromDB($ticketId)) {
                  return false;
               }
               // System comments are not conversational followups. Preserve
               // their files on the ticket but do not overwrite its content.
               if (!$this->syncNativeAttachments(
                  $syncJob,
                  $authConfig,
                  $attachments,
                  $ticket,
                  null,
                  0,
                  $date
               )) {
                  return false;
               }
               continue;
            }

            $glpiAuthorId = $this->resolveMappedId(
               $syncJob,
               'zendesk_user',
               $authorId,
               TargetItemtype::USER
            );
            if ($glpiAuthorId === null) {
               return false;
            }

            $followupId = $existingCommentLink?->itemId();
            if ($existingCommentLink !== null && $existingCommentLink->itemtype() !== TargetItemtype::ITIL_FOLLOWUP) {
               return false;
            }
            if ($existingCommentLink !== null && $followupId !== null) {
               $existingFollowup = new \ITILFollowup();
               if (!$existingFollowup->getFromDB($followupId)) {
                  // Repair a stale reconciliation link left by a manually
                  // removed/failed followup, then recreate the followup below.
                  if ($existingCommentLink->id() !== null) {
                     $this->objectLinks->delete($existingCommentLink->id());
                  }
                  $existingCommentLink = null;
                  $followupId = null;
               }
            }

            if ($followupId === null) {
               $content = $this->plainTextToGlpiHtml($this->bodyWithoutInlineImages($body));
               if ($content === '' && $attachments !== []) {
                  $content = '<p>[Pièce jointe importée depuis Zendesk]</p>';
               }
               if ($content === '') {
                  continue;
               }

               $followup = new \ITILFollowup();
               $followupId = $followup->add([
                  'itemtype' => \Ticket::class,
                  'items_id' => $ticketId,
                  'users_id' => $glpiAuthorId,
                  'content' => $content,
                  'is_private' => (($comment['public'] ?? true) === true) ? 0 : 1,
                  'date' => $date,
                  '_disablenotif' => 1,
                  '_do_not_compute_takeintoaccount' => true,
               ]);
               if ($followupId === false) {
                  return false;
               }

               $syncJobId = $syncJob->id();
               if ($syncJobId === null) {
                  return false;
               }
               $this->objectLinks->save(ObjectLink::create(
                  $syncJobId,
                  $syncJob->authConfigReference(),
                  $commentExternalId,
                  TargetItemtype::ITIL_FOLLOWUP,
                  (int) $followupId,
                  'zendesk_comment'
               ));
            }

            $followup = new \ITILFollowup();
            if (!$followup->getFromDB((int) $followupId)) {
               return false;
            }
            if (!$this->syncNativeAttachments(
               $syncJob,
               $authConfig,
               $attachments,
               $followup,
               $body,
               $glpiAuthorId,
               $date
            )) {
               return false;
            }
         }

         $nextPage = $payload['next_page'] ?? null;
         $path = is_string($nextPage) && $nextPage !== ''
            ? $this->relativePathFromZendeskUrl($authConfig->endpoint()->value(), $nextPage)
            : '';
      }

      return true;
   }

   /**
    * Zendesk normally exposes inline images in comment.attachments when
    * include_inline_images=true. Some channels/accounts still return the
    * inline URL only in body/html_body. Preserve those screenshots by
    * synthesizing an attachment descriptor from the tokenized Zendesk URL.
    *
    * @param list<mixed> $attachments
    * @return list<mixed>
    */
   private function mergeInlineAttachmentsFromCommentBodies(
      array $attachments,
      string $body,
      string $htmlBody
   ): array {
      $knownUrls = [];
      foreach ($attachments as $attachment) {
         if (!is_array($attachment)) {
            continue;
         }
         foreach (['content_url', 'mapped_content_url'] as $field) {
            if (isset($attachment[$field]) && is_string($attachment[$field]) && $attachment[$field] !== '') {
               $knownUrls[html_entity_decode($attachment[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8')] = true;
            }
         }
      }

      foreach ($this->extractInlineImageUrls($body, $htmlBody) as $url) {
         if (isset($knownUrls[$url])) {
            continue;
         }

         $parts = parse_url($url);
         $scheme = strtolower((string) ($parts['scheme'] ?? ''));
         $path = (string) ($parts['path'] ?? '');
         if ($scheme !== 'https' || !str_contains($path, '/attachments/')) {
            continue;
         }

         $fileName = $this->attachmentFileNameFromUrl($url);
         $attachments[] = [
            'id' => 'inline-' . substr(hash('sha256', $url), 0, 40),
            'content_url' => $url,
            'mapped_content_url' => $url,
            'file_name' => $fileName,
            'content_type' => $this->guessImageContentType($fileName),
            'inline' => true,
            'deleted' => false,
         ];
         $knownUrls[$url] = true;
      }

      return $attachments;
   }

   /** @return list<string> */
   private function extractInlineImageUrls(string $body, string $htmlBody): array {
      $urls = [];

      if (preg_match_all('/!\\[[^\\]]*\\]\\(\\s*(https:\\/\\/[^\\s)]+)\\s*\\)/iu', $body, $matches) > 0) {
         foreach ($matches[1] as $url) {
            if (is_string($url) && $url !== '') {
               $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
         }
      }

      if (preg_match_all("/<img\\b[^>]*\\bsrc=[\"'](https:\\/\\/[^\"']+)[\"'][^>]*>/iu", $htmlBody, $matches) > 0) {
         foreach ($matches[1] as $url) {
            if (is_string($url) && $url !== '') {
               $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
         }
      }

      return array_values(array_unique($urls));
   }

   private function attachmentFileNameFromUrl(string $url): string {
      $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
      if ($query !== '') {
         parse_str($query, $parameters);
         if (isset($parameters['name']) && is_scalar($parameters['name'])) {
            $name = trim((string) $parameters['name']);
            if ($name !== '') {
               return $this->safeFileName($name);
            }
         }
      }

      $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
      $name = basename($path);
      if ($name === '' || $name === '/' || $name === '.') {
         $name = 'zendesk-inline-image.png';
      }

      return $this->safeFileName($name);
   }

   private function guessImageContentType(string $fileName): string {
      return match (strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION))) {
         'jpg', 'jpeg' => 'image/jpeg',
         'gif' => 'image/gif',
         'webp' => 'image/webp',
         'svg' => 'image/svg+xml',
         'bmp' => 'image/bmp',
         default => 'image/png',
      };
   }

   /**
    * Import Zendesk attachments through GLPI's native rich-text/document
    * pipeline.  CommonDBTM::addFiles() is deliberately used here instead of
    * hand-crafting Document + Document_Item + <img> HTML: it is the GLPI 11
    * code path responsible for tags, document links and inline rendering.
    *
    * @param list<mixed> $attachments
    */
   private function syncNativeAttachments(
      SyncJob $syncJob,
      AuthConfig $authConfig,
      array $attachments,
      \CommonDBTM $item,
      ?string $zendeskBody,
      int $authorId,
      string $date
   ): bool {
      if ($attachments === []) {
         return true;
      }

      $workingBody = $zendeskBody;
      $tempFiles = [];
      $uploadTags = [];
      $attachmentRecords = [];
      $docData = [];

      foreach ($attachments as $attachment) {
         if (!is_array($attachment)) {
            return false;
         }
         if (($attachment['deleted'] ?? false) === true) {
            continue;
         }
         if (($attachment['malware_scan_result'] ?? '') === 'malware_found') {
            return false;
         }

         $contentUrl = isset($attachment['content_url']) && is_string($attachment['content_url'])
            ? $attachment['content_url']
            : '';
         if ($contentUrl === '') {
            continue;
         }

         $rawAttachmentId = $attachment['id'] ?? null;
         $attachmentId = is_scalar($rawAttachmentId) && (string) $rawAttachmentId !== ''
            ? (string) $rawAttachmentId
            : hash('sha256', $contentUrl);
         $externalId = new ExternalId($attachmentId);

         $existingLink = $this->objectLinks->findByExternalId(
            $syncJob->authConfigReference(),
            'zendesk_attachment',
            $externalId
         );

         $documentId = null;
         $tag = '';
         if ($existingLink !== null) {
            if ($existingLink->itemtype() !== TargetItemtype::DOCUMENT) {
               return false;
            }
            $document = new \Document();
            if (!$document->getFromDB($existingLink->itemId())) {
               // Same self-healing rule as comments: a deleted GLPI document
               // must not permanently poison the Zendesk migration mapping.
               if ($existingLink->id() !== null) {
                  $this->objectLinks->delete($existingLink->id());
               }
               $existingLink = null;
            } else {
               $documentId = (int) $document->getID();
               $tag = (string) ($document->fields['tag'] ?? '');
               if ($tag === '') {
                  return false;
               }
               $docData[$documentId] = $document->fields;
            }
         }
         if ($existingLink === null) {
            $fileName = $this->safeFileName((string) ($attachment['file_name'] ?? ('zendesk-' . $attachmentId)));
            $tempName = $this->downloadAttachmentToTemp($authConfig, $contentUrl, $fileName, $attachmentId);
            if ($tempName === null) {
               return false;
            }
            $tag = $this->newDocumentTag();
            $tempFiles[] = $tempName;
            $uploadTags[] = $tag;
         }

         $inline = ($attachment['inline'] ?? false) === true
            && str_starts_with(strtolower((string) ($attachment['content_type'] ?? '')), 'image/');

         if ($workingBody !== null && $inline) {
            [$workingBody, $replaced] = $this->replaceZendeskInlineImageWithTag(
               $workingBody,
               $attachment,
               \Document::getImageTag($tag)
            );
            if (!$replaced) {
               $workingBody = rtrim($workingBody) . "\n\n" . \Document::getImageTag($tag);
            }
         }

         $attachmentRecords[] = [
            'external_id' => $externalId,
            'document_id' => $documentId,
            'tag' => $tag,
         ];
      }

      if ($attachmentRecords === []) {
         return true;
      }

      $input = [
         '_disablenotif' => 1,
         'users_id' => $authorId,
      ];
      if ($workingBody !== null) {
         $input['content'] = $this->plainTextToGlpiHtml($workingBody);
      }
      if ($tempFiles !== []) {
         $input['_filename'] = $tempFiles;
         $input['_tag_filename'] = $uploadTags;
      }

      // addFiles() performs the native GLPI upload, Document_Item linking and
      // tag-to-image conversion. force_update mirrors ITILFollowup::post_addItem().
      $processed = $item->addFiles($input, [
         'force_update' => true,
         'date' => $date,
      ]);

      // Resolve documents created by addFiles() using the tags we assigned.
      foreach ($attachmentRecords as &$record) {
         if ($record['document_id'] !== null) {
            continue;
         }
         $document = new \Document();
         if (!$document->getFromDBByCrit(['tag' => $record['tag']])) {
            $this->cleanupTempFiles($tempFiles);
            return false;
         }
         $record['document_id'] = (int) $document->getID();
         $docData[(int) $document->getID()] = $document->fields;
      }
      unset($record);

      $this->cleanupTempFiles($tempFiles);

      // Existing documents are not part of addFiles()' local $docadded array.
      // Run the same GLPI converter once more with the complete set, then use
      // updateInDB() exactly as GLPI's own addFiles(force_update=true) does.
      if ($workingBody !== null) {
         $content = isset($processed['content']) && is_string($processed['content'])
            ? $processed['content']
            : $this->plainTextToGlpiHtml($workingBody);
         $content = \Toolbox::convertTagToImage($content, $item, $docData);
         $oldContent = (string) ($item->fields['content'] ?? '');
         if ($content !== '' && $content !== $oldContent) {
            $item->fields['content'] = $content;
            if (!$item->updateInDB(['content'], ['content' => $oldContent])) {
               return false;
            }
         }
      }

      $syncJobId = $syncJob->id();
      if ($syncJobId === null) {
         return false;
      }
      foreach ($attachmentRecords as $record) {
         $existing = $this->objectLinks->findByExternalId(
            $syncJob->authConfigReference(),
            'zendesk_attachment',
            $record['external_id']
         );
         if ($existing !== null) {
            continue;
         }
         $this->objectLinks->save(ObjectLink::create(
            $syncJobId,
            $syncJob->authConfigReference(),
            $record['external_id'],
            TargetItemtype::DOCUMENT,
            (int) $record['document_id'],
            'zendesk_attachment'
         ));
      }

      return true;
   }

   /**
    * Replace Zendesk Markdown/HTML inline-image references with GLPI's native
    * #document-tag# token.  The token is later converted by addFiles().
    *
    * @param array<string, mixed> $attachment
    * @return array{0:string,1:bool}
    */
   private function replaceZendeskInlineImageWithTag(string $body, array $attachment, string $imageTag): array {
      $replaced = false;
      $urls = [];
      foreach (['content_url', 'mapped_content_url'] as $field) {
         if (isset($attachment[$field]) && is_string($attachment[$field]) && $attachment[$field] !== '') {
            $urls[] = html_entity_decode($attachment[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8');
         }
      }

      foreach (array_unique($urls) as $url) {
         $quoted = preg_quote($url, '/');
         $patterns = [
            '/!\\[[^\\]]*\\]\\(\\s*' . $quoted . '\\s*\\)/iu',
            "/<img\\b[^>]*\\bsrc=[\"']" . $quoted . "[\"'][^>]*>/iu",
         ];
         foreach ($patterns as $pattern) {
            $body = preg_replace_callback(
               $pattern,
               static function () use ($imageTag, &$replaced): string {
                  $replaced = true;
                  return $imageTag;
               },
               $body
            ) ?? $body;
         }
      }

      return [$body, $replaced];
   }

   private function newDocumentTag(): string {
      return sprintf(
         '%s-%s-%s-%s-%s',
         bin2hex(random_bytes(4)),
         bin2hex(random_bytes(2)),
         bin2hex(random_bytes(2)),
         bin2hex(random_bytes(2)),
         bin2hex(random_bytes(6))
      );
   }

   /** @param list<string> $tempFiles */
   private function cleanupTempFiles(array $tempFiles): void {
      foreach ($tempFiles as $tempName) {
         $path = rtrim((string) GLPI_TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tempName;
         if (is_file($path)) {
            @unlink($path);
         }
      }
   }

   /**
    * @param list<mixed> $attachments
    * @param array<string, array{id:int, content_url:string, mapped_content_url:string, inline:bool, content_type:string, file_name:string, tag:string}> $imported
    */
   private function importAttachments(
      SyncJob $syncJob,
      AuthConfig $authConfig,
      array $attachments,
      int $ticketId,
      ?int $followupId,
      int $authorId,
      string $date,
      array &$imported = []
   ): bool {
      if ($attachments === []) {
         return true;
      }

      $ticket = new \Ticket();
      if (!$ticket->getFromDB($ticketId)) {
         return false;
      }
      $entityId = (int) ($ticket->fields['entities_id'] ?? 0);

      foreach ($attachments as $attachment) {
         if (!is_array($attachment)) {
            return false;
         }
         if (($attachment['deleted'] ?? false) === true) {
            continue;
         }
         if (($attachment['malware_scan_result'] ?? '') === 'malware_found') {
            return false;
         }

         $rawAttachmentId = $attachment['id'] ?? null;
         $contentUrl = isset($attachment['content_url']) && is_string($attachment['content_url'])
            ? $attachment['content_url']
            : '';
         if ($contentUrl === '') {
            continue;
         }

         $attachmentId = is_scalar($rawAttachmentId) && (string) $rawAttachmentId !== ''
            ? (string) $rawAttachmentId
            : hash('sha256', $contentUrl);
         $externalId = new ExternalId($attachmentId);

         $existingAttachmentLink = $this->objectLinks->findByExternalId(
            $syncJob->authConfigReference(),
            'zendesk_attachment',
            $externalId
         );
         if ($existingAttachmentLink !== null) {
            if ($existingAttachmentLink->itemtype() !== TargetItemtype::DOCUMENT) {
               return false;
            }
            $existingDocument = new \Document();
            if (!$existingDocument->getFromDB($existingAttachmentLink->itemId())) {
               return false;
            }
            $this->rememberImportedAttachment($imported, $attachment, $existingDocument);
            continue;
         }

         $fileName = $this->safeFileName((string) ($attachment['file_name'] ?? ('zendesk-' . $attachmentId)));
         $tempName = $this->downloadAttachmentToTemp($authConfig, $contentUrl, $fileName, $attachmentId);
         if ($tempName === null) {
            return false;
         }

         $document = new \Document();
         $targetItemtype = $followupId !== null ? \ITILFollowup::class : \Ticket::class;
         $targetId = $followupId ?? $ticketId;
         $documentId = $document->add([
            'name' => $fileName,
            'entities_id' => $entityId,
            'is_recursive' => 0,
            'tickets_id' => $ticketId,
            'itemtype' => $targetItemtype,
            'items_id' => $targetId,
            'users_id' => $authorId,
            '_only_if_upload_succeed' => 1,
            '_filename' => [$tempName],
            '_disablenotif' => 1,
            'date' => $date,
         ]);

         $tempPath = rtrim((string) GLPI_TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tempName;
         if (is_file($tempPath)) {
            @unlink($tempPath);
         }

         if ($documentId === false) {
            return false;
         }

         if (!$document->getFromDB((int) $documentId)) {
            return false;
         }
         $this->rememberImportedAttachment($imported, $attachment, $document);

         $syncJobId = $syncJob->id();
         if ($syncJobId === null) {
            return false;
         }
         $this->objectLinks->save(ObjectLink::create(
            $syncJobId,
            $syncJob->authConfigReference(),
            $externalId,
            TargetItemtype::DOCUMENT,
            (int) $documentId,
            'zendesk_attachment'
         ));
      }

      return true;
   }

   /**
    * @param array<string, array{id:int, content_url:string, mapped_content_url:string, inline:bool, content_type:string, file_name:string, tag:string}> $imported
    * @param array<string, mixed> $attachment
    */
   private function rememberImportedAttachment(array &$imported, array $attachment, \Document $document): void {
      $contentUrl = isset($attachment['content_url']) && is_string($attachment['content_url'])
         ? $attachment['content_url']
         : '';
      $mappedContentUrl = isset($attachment['mapped_content_url']) && is_string($attachment['mapped_content_url'])
         ? $attachment['mapped_content_url']
         : '';
      $data = [
         'id' => (int) $document->getID(),
         'content_url' => $contentUrl,
         'mapped_content_url' => $mappedContentUrl,
         'inline' => ($attachment['inline'] ?? false) === true,
         'content_type' => (string) ($attachment['content_type'] ?? ($document->fields['mime'] ?? '')),
         'file_name' => (string) ($attachment['file_name'] ?? ($document->fields['filename'] ?? 'attachment')),
         'tag' => (string) ($document->fields['tag'] ?? ''),
      ];

      if ($contentUrl !== '') {
         $imported[$contentUrl] = $data;
      }
      if ($mappedContentUrl !== '') {
         $imported[$mappedContentUrl] = $data;
      }
      // Also keep a stable attachment-id based key for inline files whose body
      // URL differs from content_url/mapped_content_url.
      $imported['document:' . $data['id']] = $data;
   }

   /**
    * @param array<string, array{id:int, content_url:string, mapped_content_url:string, inline:bool, content_type:string, file_name:string, tag:string}> $attachments
    */
   private function embedInlineImagesIntoFollowup(int $followupId, string $zendeskBody, array $attachments): bool {
      $followup = new \ITILFollowup();
      if (!$followup->getFromDB($followupId)) {
         return false;
      }

      $content = $this->renderCommentWithLocalImages($zendeskBody, $attachments, $followup);
      if ($content === '') {
         return true;
      }

      if ((string) ($followup->fields['content'] ?? '') === $content) {
         return true;
      }

      return (bool) $followup->update([
         'id' => $followupId,
         'content' => $content,
         '_disablenotif' => 1,
      ]);
   }

   /**
    * @param array<string, array{id:int, content_url:string, mapped_content_url:string, inline:bool, content_type:string, file_name:string, tag:string}> $attachments
    */
   private function embedInlineImagesIntoTicket(int $ticketId, string $zendeskBody, array $attachments): bool {
      if (!$this->hasInlineImage($attachments)) {
         return true;
      }

      $ticket = new \Ticket();
      if (!$ticket->getFromDB($ticketId)) {
         return false;
      }

      $content = $this->renderCommentWithLocalImages($zendeskBody, $attachments, $ticket);
      if ($content === '') {
         return true;
      }

      return (bool) $ticket->update([
         'id' => $ticketId,
         'content' => $content,
         '_disablenotif' => 1,
      ]);
   }

   /**
    * @param array<string, array{id:int, content_url:string, mapped_content_url:string, inline:bool, content_type:string, file_name:string, tag:string}> $attachments
    */
   private function renderCommentWithLocalImages(string $body, array $attachments, \CommonDBTM $item): string {
      $placeholders = [];
      $counter = 0;

      $text = preg_replace_callback(
         '/!\\[([^\\]]*)\\]\\((https?:\\/\\/[^)]+)\\)/iu',
         function (array $matches) use (&$placeholders, &$counter, $attachments, $item): string {
            $url = html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $attachment = $attachments[$url] ?? null;
            if ($attachment === null || !$this->isImageAttachment($attachment)) {
               return (string) $matches[0];
            }
            $counter++;
            $token = sprintf('UXCONNECTOR_INLINE_IMAGE_%d', $counter);
            $placeholders[$token] = $this->nativeGlpiImageHtml($attachment, $item);
            return $token;
         },
         $body
      );
      if ($text === null) {
         $text = $body;
      }

      $html = $this->plainTextToGlpiHtml($text);
      foreach ($placeholders as $token => $imageHtml) {
         $html = str_replace($token, $imageHtml, $html);
      }

      // Some Zendesk channels do not leave a Markdown image marker in body even
      // though include_inline_images=true exposes the attachment. Append such
      // inline images once so the GLPI conversation keeps the screenshot.
      $seenDocumentIds = [];
      foreach ($placeholders as $imageHtml) {
         if (preg_match('/docid=(\\d+)/', $imageHtml, $match) === 1) {
            $seenDocumentIds[(int) $match[1]] = true;
         }
      }
      foreach ($attachments as $attachment) {
         $documentId = $attachment['id'];
         if (
            !$attachment['inline']
            || !$this->isImageAttachment($attachment)
            || isset($seenDocumentIds[$documentId])
         ) {
            continue;
         }
         $html .= '<p>' . $this->nativeGlpiImageHtml($attachment, $item) . '</p>';
         $seenDocumentIds[$documentId] = true;
      }

      return trim($html);
   }

   /**
    * @param array{id:int, content_url:string, mapped_content_url:string, inline:bool, content_type:string, file_name:string, tag:string} $attachment
    */
   private function nativeGlpiImageHtml(array $attachment, \CommonDBTM $item): string {
      $document = new \Document();
      if (!$document->getFromDB($attachment['id'])) {
         return '';
      }
      $tag = (string) ($document->fields['tag'] ?? $attachment['tag']);
      if ($tag === '') {
         return '';
      }

      return \Toolbox::convertTagToImage(
         \Document::getImageTag($tag),
         $item,
         [$attachment['id'] => $document->fields]
      );
   }

   /**
    * @param array<string, array{id:int, content_url:string, mapped_content_url:string, inline:bool, content_type:string, file_name:string, tag:string}> $attachments
    */
   private function hasInlineImage(array $attachments): bool {
      foreach ($attachments as $attachment) {
         if ($attachment['inline'] && $this->isImageAttachment($attachment)) {
            return true;
         }
      }
      return false;
   }

   /**
    * @param array{id:int, content_url:string, mapped_content_url:string, inline:bool, content_type:string, file_name:string, tag:string} $attachment
    */
   private function isImageAttachment(array $attachment): bool {
      return str_starts_with(strtolower($attachment['content_type']), 'image/');
   }

   private function bodyWithoutInlineImages(string $body): string {
      $withoutImages = preg_replace('/!\\[[^\\]]*\\]\\(https?:\\/\\/[^)]+\\)/iu', '', $body);
      return trim($withoutImages ?? $body);
   }

   private function plainTextToGlpiHtml(string $text): string {
      $text = trim($text);
      if ($text === '') {
         return '';
      }
      return '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), false) . '</p>';
   }

   private function downloadAttachmentToTemp(
      AuthConfig $authConfig,
      string $contentUrl,
      string $fileName,
      string $attachmentId
   ): ?string {
      $tempName = sprintf(
         'uxconnector-zendesk-%s-%s',
         preg_replace('/[^A-Za-z0-9_-]/', '_', $attachmentId) ?? 'attachment',
         $fileName
      );
      $tempPath = rtrim((string) GLPI_TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tempName;

      // Tokenized Zendesk attachment URLs are designed to be dereferenced as
      // URLs. Try them without forwarding OAuth credentials first; this also
      // safely covers redirects to Zendesk's attachment CDN. The destination
      // policy is still enforced for every hop.
      $direct = $this->downloadUnauthenticatedUrlToTemp($contentUrl, $tempPath, $tempName);
      if ($direct !== null) {
         return $direct;
      }

      $relative = $this->relativePathFromZendeskUrl($authConfig->endpoint()->value(), $contentUrl);
      if ($relative !== '') {
         // Fallback for accounts where the attachment URL itself is protected.
         $response = $this->httpEngine->execute($authConfig, new HttpRequest(HttpMethod::GET, $relative));
         if ($response->isSuccessful()) {
            if (strlen($response->body) > self::MAX_ATTACHMENT_BYTES) {
               return null;
            }
            return file_put_contents($tempPath, $response->body) !== false ? $tempName : null;
         }

         if ($response->statusCode >= 300 && $response->statusCode < 400) {
            $location = $response->header('Location');
            if (is_string($location) && $location !== '') {
               return $this->downloadUnauthenticatedUrlToTemp($location, $tempPath, $tempName);
            }
         }
      }

      return null;
   }

   private function downloadUnauthenticatedUrlToTemp(string $url, string $tempPath, string $tempName): ?string {
      $currentUrl = $url;

      for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
         try {
            // Attachment URLs legitimately contain query parameters
            // (`?name=...` on Zendesk and signed `?token=...` values on
            // zdusercontent.com). The generic Endpoint value object is for
            // base API endpoints and deliberately rejects query strings, so
            // validating the complete attachment URL with Endpoint caused
            // every tokenized Zendesk image to be rejected before download.
            //
            // Validate the full URL shape here, but apply the existing SSRF
            // destination policy to its HTTPS origin only. The actual request
            // still uses the complete signed URL.
            $this->assertAllowedDownloadUrl($currentUrl);
         } catch (\Throwable) {
            return null;
         }

         $context = stream_context_create([
            'http' => [
               'method' => 'GET',
               'timeout' => self::DOWNLOAD_TIMEOUT_SECONDS,
               'ignore_errors' => true,
               'follow_location' => 0,
               'max_redirects' => 0,
               'header' => "User-Agent: UXConnector/2.17.1\r\n",
            ],
         ]);

         $stream = @fopen($currentUrl, 'rb', false, $context);
         if ($stream === false) {
            return null;
         }

         $meta = stream_get_meta_data($stream);
         /** @var list<string> $headers */
         $headers = $meta['wrapper_data'] ?? [];
         $status = $this->parseStatusCode($headers);

         if ($status >= 300 && $status < 400) {
            fclose($stream);
            $location = $this->headerValue($headers, 'Location');
            if ($location === null || $location === '') {
               return null;
            }
            $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
            continue;
         }

         if ($status < 200 || $status >= 300) {
            fclose($stream);
            return null;
         }

         $target = @fopen($tempPath, 'wb');
         if ($target === false) {
            fclose($stream);
            return null;
         }

         $bytes = 0;
         $success = true;
         while (!feof($stream)) {
            $chunk = fread($stream, self::DOWNLOAD_CHUNK_BYTES);
            if ($chunk === false) {
               $success = false;
               break;
            }
            $bytes += strlen($chunk);
            if ($bytes > self::MAX_ATTACHMENT_BYTES || fwrite($target, $chunk) === false) {
               $success = false;
               break;
            }
         }

         fclose($target);
         fclose($stream);

         if (!$success) {
            @unlink($tempPath);
            return null;
         }

         return $tempName;
      }

      return null;
   }

   /**
    * Validate a downloadable attachment URL without stripping the signed
    * query string used by Zendesk/CDN URLs. DestinationPolicy only needs the
    * origin host in order to resolve it and reject forbidden network ranges.
    */
   private function assertAllowedDownloadUrl(string $url): void {
      if (filter_var($url, FILTER_VALIDATE_URL) === false) {
         throw new \InvalidArgumentException('Invalid attachment URL.');
      }

      $parts = parse_url($url);
      if (!is_array($parts)) {
         throw new \InvalidArgumentException('Invalid attachment URL.');
      }

      $scheme = strtolower((string) ($parts['scheme'] ?? ''));
      $host = (string) ($parts['host'] ?? '');
      if ($scheme !== 'https' || $host === '') {
         throw new \InvalidArgumentException('Attachment URL must use HTTPS.');
      }
      if (isset($parts['user']) || isset($parts['pass'])) {
         throw new \InvalidArgumentException('Attachment URL must not contain embedded credentials.');
      }

      $hostForUrl = str_contains($host, ':') && !str_starts_with($host, '[')
         ? '[' . $host . ']'
         : $host;
      $origin = 'https://' . $hostForUrl;
      if (isset($parts['port'])) {
         $origin .= ':' . (int) $parts['port'];
      }

      $this->destinationPolicy->assertAllowed(new Endpoint($origin));
   }

   /** @param list<string> $headers */
   private function parseStatusCode(array $headers): int {
      foreach ($headers as $header) {
         if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
            return (int) $matches[1];
         }
      }
      return 0;
   }

   /** @param list<string> $headers */
   private function headerValue(array $headers, string $name): ?string {
      $prefix = strtolower($name) . ':';
      foreach ($headers as $header) {
         if (str_starts_with(strtolower($header), $prefix)) {
            return trim(substr($header, strlen($prefix)));
         }
      }
      return null;
   }

   private function resolveRedirectUrl(string $baseUrl, string $location): string {
      if (preg_match('#^https://#i', $location) === 1) {
         return $location;
      }
      if (str_starts_with($location, '//')) {
         return 'https:' . $location;
      }

      $parts = parse_url($baseUrl);
      if ($parts === false || !isset($parts['host'])) {
         return '';
      }
      $origin = 'https://' . $parts['host'];
      if (isset($parts['port'])) {
         $origin .= ':' . $parts['port'];
      }
      if (str_starts_with($location, '/')) {
         return $origin . $location;
      }

      $basePath = (string) ($parts['path'] ?? '/');
      $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
      return $origin . ($directory !== '' ? $directory : '') . '/' . $location;
   }

   private function safeFileName(string $value): string {
      $value = basename(str_replace('\\', '/', trim($value)));
      $value = preg_replace('/[\x00-\x1F\x7F]/u', '_', $value) ?? '';
      $value = preg_replace('/[^\pL\pN._()\[\] -]+/u', '_', $value) ?? '';
      $value = trim($value, " .\t\n\r\0\x0B");
      if ($value === '') {
         return 'zendesk-attachment.bin';
      }
      return mb_substr($value, 0, 180);
   }

   private function requesterType(): int {
      return defined('CommonITILActor::REQUESTER') ? \CommonITILActor::REQUESTER : 1;
   }

   private function assignType(): int {
      return defined('CommonITILActor::ASSIGN') ? \CommonITILActor::ASSIGN : 2;
   }

   private function toGlpiDate(string $value): string {
      if ($value === '') {
         return date('Y-m-d H:i:s');
      }
      try {
         return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
      } catch (\Throwable) {
         return date('Y-m-d H:i:s');
      }
   }

   private function normalizeText(string $value): string {
      $value = preg_replace('/\s+/u', ' ', trim($value));
      return $value ?? '';
   }

   private function relativePathFromZendeskUrl(string $endpoint, string $url): string {
      $endpointParts = parse_url($endpoint);
      $urlParts = parse_url($url);
      if ($endpointParts === false || $urlParts === false) {
         return '';
      }
      if (($endpointParts['scheme'] ?? '') !== ($urlParts['scheme'] ?? '')) {
         return '';
      }
      if (($endpointParts['host'] ?? '') !== ($urlParts['host'] ?? '')) {
         return '';
      }
      if (($endpointParts['port'] ?? null) !== ($urlParts['port'] ?? null)) {
         return '';
      }

      $path = (string) ($urlParts['path'] ?? '/');
      if (isset($urlParts['query']) && $urlParts['query'] !== '') {
         $path .= '?' . $urlParts['query'];
      }
      return $path;
   }
}
