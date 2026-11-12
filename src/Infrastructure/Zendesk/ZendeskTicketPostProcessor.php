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
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\PostProcessingDiagnosticInterface;
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
final class ZendeskTicketPostProcessor implements InboundRecordPostProcessorInterface, PostProcessingDiagnosticInterface
{
    private const MAX_COMMENT_PAGES = 1000;
    private const MAX_REDIRECTS = 3;
    private const MAX_ATTACHMENT_BYTES = 50 * 1024 * 1024;

    private string $lastErrorCode = 'POST_PROCESSING_WARNING';
    private string $lastErrorMessage = 'Ticket post-processing incomplete; inspect the source and retry this ticket.';

    public function lastErrorCode(): string
    {
        return $this->lastErrorCode;
    }

    public function lastErrorMessage(): string
    {
        return $this->lastErrorMessage;
    }

    private function failWith(string $code, string $message): bool
    {
        // First detailed failure wins. Subsequent stages still run where possible.
        if ($this->lastErrorCode === 'POST_PROCESSING_WARNING') {
            $this->lastErrorCode = $code;
            $this->lastErrorMessage = $message;
        }
        return false;
    }

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

    public function supports(SyncJob $syncJob): bool
    {
        return in_array($syncJob->templateId(), self::SUPPORTED_TEMPLATES, true);
    }

    public function process(SyncJob $syncJob, array $sourceRecord, ExternalId $externalId): bool
    {
        $this->lastErrorCode = 'POST_PROCESSING_WARNING';
        $this->lastErrorMessage = 'Ticket post-processing incomplete; inspect the source and retry this ticket.';
        $ticketLink = $this->objectLinks->findByExternalId(
            $syncJob->authConfigReference(),
            $syncJob->externalObjectType(),
            $externalId,
        );
        if ($ticketLink === null || $ticketLink->itemtype() !== TargetItemtype::TICKET) {
            return $this->failWith('TICKET_LINK_MISSING', 'Target GLPI ticket reconciliation link was not found.');
        }

        $ticketId = $ticketLink->itemId();

        // Enrichments are intentionally independent. A temporarily missing
        // organization/user/group reconciliation link must not prevent the ITIL
        // category, comments or attachments from being imported. Returning false
        // when any stage failed keeps the page checkpoint replay-safe: a later run
        // can heal the missing relation without duplicating comments/documents.
        $relationsOk = $this->applyEntityAndCategory($syncJob, $sourceRecord, $ticketId);
        $actorsOk = $this->applyActors($syncJob, $sourceRecord, $ticketId);
        $commentsOk = $this->importComments($syncJob, $sourceRecord, $ticketId, $externalId);

        if (!$relationsOk || !$actorsOk) {
            $this->failWith(
                'ACTOR_OR_CATEGORY_UNRESOLVED',
                'One or more Zendesk actors, organizations or ITIL categories could not be matched.'
            );
        }
        return $relationsOk && $actorsOk && $commentsOk;
    }

    /** @param array<string, mixed> $sourceRecord */
    private function applyEntityAndCategory(SyncJob $syncJob, array $sourceRecord, int $ticketId): bool
    {
        $ok = true;

        $current = new \Ticket();
        if (!$current->getFromDB($ticketId)) {
            return $this->failWith(
                'TARGET_TICKET_UNAVAILABLE',
                'Target GLPI ticket is unavailable during category processing.'
            );
        }
        // Entity assignment was applied at creation by the job strategy. Root 0
        // is an intentional entity, not an empty value to replace on replay.
        if ((int) ($current->fields['itilcategories_id'] ?? 0) > 0) {
            return true;
        }

        $topicValue = $this->topicCatalog->topicValueFromTicket($syncJob, $sourceRecord);
        if ($topicValue !== null) {
            $label = $this->topicCatalog->labelForValue($syncJob, $topicValue);
            $topicDescription = $label === null
                ? sprintf(
                    'Libellé indisponible dans le catalogue Zendesk, valeur technique « %s »',
                    $this->diagnosticText($topicValue)
                )
                : sprintf('Sujet Zendesk « %s »', $this->diagnosticText($label));
            $categoryId = $this->resolveMappedId(
                $syncJob,
                'zendesk_topic',
                $topicValue,
                TargetItemtype::ITIL_CATEGORY,
            );

            // Delta safety: if a new Zendesk topic appears after the dedicated
            // category bootstrap job, create its hierarchy on demand instead of
            // leaving the new ticket uncategorized.
            if ($categoryId === null) {
                if ($label !== null) {
                    try {
                        $categoryId = $this->topicCategories->ensureForTopic($syncJob, $topicValue, $label);
                    } catch (\Throwable) {
                        return $this->failWith(
                            'TOPIC_CATEGORY_UNRESOLVED',
                            $topicDescription . ' : création ou rapprochement de la catégorie GLPI impossible.'
                        );
                    }
                }
            }

            if ($categoryId === null) {
                $ok = $this->failWith(
                    'TOPIC_CATEGORY_UNRESOLVED',
                    $topicDescription . ' : aucune catégorie ITIL GLPI utilisable.'
                );
            } else {
                $ticket = new \Ticket();
                if (
                    !(bool) $ticket->update([
                    'id' => $ticketId,
                    'itilcategories_id' => $categoryId,
                    ])
                ) {
                    $ok = $this->failWith(
                        'CATEGORY_WRITE_FAILED',
                        $topicDescription . ' : GLPI a refusé son affectation au ticket.'
                    );
                }
            }
        }

        return $ok;
    }

    /** @param array<string, mixed> $sourceRecord */
    private function applyActors(SyncJob $syncJob, array $sourceRecord, int $ticketId): bool
    {
        $ok = true;
        $allowAdditionalAssignees = $syncJob->templateId() === 'zendesk.tickets.delta.inbound';

        if (
            !$this->hasTicketRole($ticketId, $this->requesterType(), false) && isset(
                $sourceRecord['requester_id']
            ) && is_scalar($sourceRecord['requester_id'])
        ) {
            $userId = $this->resolveMappedId(
                $syncJob,
                'zendesk_user',
                (string) $sourceRecord['requester_id'],
                TargetItemtype::USER,
            );
            if ($userId === null) {
                $ok = $this->failWith('REQUESTER_LINK_UNRESOLVED', sprintf(
                    'Zendesk requester_id=%s has no usable ObjectLink for this connection.',
                    $this->diagnosticId($sourceRecord['requester_id']),
                ));
            } elseif (!$this->ensureTicketUser($ticketId, $userId, $this->requesterType())) {
                $ok = $this->failWith('REQUESTER_WRITE_FAILED', 'GLPI refused the resolved requester_id association.');
            }
        }

        if (
            ($allowAdditionalAssignees || !$this->hasTicketRole($ticketId, $this->assignType(), false))
            && isset($sourceRecord['assignee_id'])
            && is_scalar($sourceRecord['assignee_id'])
            && (string) $sourceRecord['assignee_id'] !== ''
        ) {
            $userId = $this->resolveMappedId(
                $syncJob,
                'zendesk_user',
                (string) $sourceRecord['assignee_id'],
                TargetItemtype::USER,
            );
            if ($userId === null) {
                $ok = $this->failWith('ASSIGNEE_LINK_UNRESOLVED', sprintf(
                    'Zendesk assignee_id=%s has no usable ObjectLink for this connection.',
                    $this->diagnosticId($sourceRecord['assignee_id']),
                ));
            } elseif (!$this->ensureTicketUser($ticketId, $userId, $this->assignType(), $allowAdditionalAssignees)) {
                $ok = $this->failWith('ASSIGNEE_WRITE_FAILED', 'GLPI refused the resolved assignee_id association.');
            }
        }

        if (
            ($allowAdditionalAssignees || !$this->hasTicketRole($ticketId, $this->assignType(), true))
            && isset($sourceRecord['group_id'])
            && is_scalar($sourceRecord['group_id'])
            && (string) $sourceRecord['group_id'] !== ''
        ) {
            $groupId = $this->resolveMappedId(
                $syncJob,
                'zendesk_group',
                (string) $sourceRecord['group_id'],
                TargetItemtype::GROUP,
            );
            if ($groupId === null) {
                $ok = $this->failWith('GROUP_LINK_UNRESOLVED', sprintf(
                    'Zendesk group_id=%s has no usable ObjectLink for this connection.',
                    $this->diagnosticId($sourceRecord['group_id']),
                ));
            } elseif (!$this->ensureTicketGroup($ticketId, $groupId, $this->assignType(), $allowAdditionalAssignees)) {
                $ok = $this->failWith('GROUP_WRITE_FAILED', 'GLPI refused the resolved group_id association.');
            }
        }

        return $ok;
    }

    private function diagnosticText(string $value): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        return mb_substr($text ?? '[invalid text]', 0, 1000);
    }

    private function diagnosticId(mixed $value): string
    {
        // Only Zendesk numeric identifiers are safe to display in diagnostics.
        return (is_int($value) || is_string($value))
            && preg_match('/^[0-9]{1,20}$/D', (string) $value) === 1
                ? (string) $value
                : '[invalid ID]';
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
            new ExternalId($externalId),
        );
        if ($link === null || $link->itemtype() !== $expectedType) {
            return null;
        }

        return $link->itemId();
    }

    private function hasTicketRole(int $ticketId, int $type, bool $group): bool
    {
        $model = $group ? new \Group_Ticket() : new \Ticket_User();
        return $model->find(['tickets_id' => $ticketId, 'type' => $type], [], 1) !== [];
    }

    private function ensureTicketUser(int $ticketId, int $userId, int $type, bool $allowAdditional = false): bool
    {
        if (!$allowAdditional && $this->hasTicketRole($ticketId, $type, false)) {
            return true;
        }
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
            ...($allowAdditional ? ['_from_object' => true] : []),
            'use_notification' => 0,
        ]) !== false;
    }

    private function ensureTicketGroup(int $ticketId, int $groupId, int $type, bool $allowAdditional = false): bool
    {
        if (!$allowAdditional && $this->hasTicketRole($ticketId, $type, true)) {
            return true;
        }
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
            ...($allowAdditional ? ['_from_object' => true] : []),
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
            return $this->failWith(
                'COMMENTS_AUTH_MISSING',
                'Zendesk connection is unavailable while importing comments.'
            );
        }

        $ticketIdZendesk = rawurlencode($ticketExternalId->value());
        $path = sprintf(
            '/api/v2/tickets/%s/comments.json?per_page=100&include=users&include_inline_images=true',
            $ticketIdZendesk,
        );
        $page = 0;
        $commentIndex = 0;
        $allCommentsOk = true;
        $description = $this->normalizeText((string) ($sourceRecord['description'] ?? ''));

        while ($path !== '') {
            $page++;
            if ($page > self::MAX_COMMENT_PAGES) {
                return $this->failWith(
                    'COMMENTS_PAGE_LIMIT',
                    'Zendesk comment pagination exceeded the configured safety limit.'
                );
            }

            try {
                $response = $this->httpEngine->execute($authConfig, new HttpRequest(HttpMethod::GET, $path));
            } catch (\Throwable) {
                return $this->failWith(
                    'COMMENTS_HTTP_TRANSPORT',
                    'Unable to retrieve Zendesk ticket comments (HTTP transport).'
                );
            }
            if (!$response->isSuccessful()) {
                return $this->failWith('COMMENTS_HTTP_STATUS', sprintf(
                    'Zendesk ticket comments returned HTTP %d.',
                    $response->statusCode
                ));
            }

            try {
                $payload = $response->json();
            } catch (\Throwable) {
                return $this->failWith('COMMENTS_INVALID_JSON', 'Zendesk comments returned an invalid JSON payload.');
            }
            $comments = $payload['comments'] ?? null;
            if (!is_array($comments)) {
                return $this->failWith(
                    'COMMENTS_INVALID_RESPONSE',
                    'Zendesk comments payload is missing its comments collection.'
                );
            }

            foreach ($comments as $comment) {
                if (!is_array($comment) || !isset($comment['id'])) {
                    $allCommentsOk = $this->failWith(
                        'COMMENTS_INVALID_RECORD',
                        'A Zendesk comment record has no identifier.'
                    );
                    continue;
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
                    (string) ($comment['html_body'] ?? ''),
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
                    if (
                        !$this->syncCommentAttachments(
                            $syncJob,
                            $authConfig,
                            $attachments,
                            $ticket,
                            $body,
                            0,
                            $date,
                        )
                    ) {
                        $allCommentsOk = false;
                    }
                    continue;
                }

                $existingCommentLink = $this->objectLinks->findByExternalId(
                    $syncJob->authConfigReference(),
                    'zendesk_comment',
                    $commentExternalId,
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
                    if (
                        !$this->syncCommentAttachments(
                            $syncJob,
                            $authConfig,
                            $attachments,
                            $ticket,
                            null,
                            0,
                            $date,
                        )
                    ) {
                        $allCommentsOk = false;
                    }
                    continue;
                }

                $glpiAuthorId = $this->resolveMappedId(
                    $syncJob,
                    'zendesk_user',
                    $authorId,
                    TargetItemtype::USER,
                );
                if ($glpiAuthorId === null) {
                    // Do not lose files just because users were not synchronized yet.
                    // Keep files on the ticket, and retry this warning later to create
                    // the correctly attributed followup without inventing an author.
                    $ticket = new \Ticket();
                    if ($ticket->getFromDB($ticketId) && $attachments !== []) {
                        $this->syncCommentAttachments($syncJob, $authConfig, $attachments, $ticket, null, 0, $date);
                    }
                    $allCommentsOk = $this->failWith('COMMENT_AUTHOR_UNRESOLVED', sprintf(
                        'Zendesk comment author_id=%s has no usable user ObjectLink for this connection; '
                            . 'the comment remains retryable.',
                        $this->diagnosticId($authorId),
                    ));
                    continue;
                }

                $followupId = $existingCommentLink?->itemId();
                if (
                    $existingCommentLink !== null && $existingCommentLink->itemtype() !== TargetItemtype::ITIL_FOLLOWUP
                ) {
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
                        $content = '<p>[Pièce jointe Zendesk : téléchargement désactivé ou en attente]</p>';
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
                        'zendesk_comment',
                    ));
                }

                $followup = new \ITILFollowup();
                if (!$followup->getFromDB((int) $followupId)) {
                    return false;
                }
                if (
                    !$this->syncCommentAttachments(
                        $syncJob,
                        $authConfig,
                        $attachments,
                        $followup,
                        $body,
                        $glpiAuthorId,
                        $date,
                    )
                ) {
                    $allCommentsOk = false;
                }
            }

            $nextPage = $payload['next_page'] ?? null;
            $path = is_string($nextPage) && $nextPage !== ''
               ? $this->relativePathFromZendeskUrl($authConfig->endpoint()->value(), $nextPage)
               : '';
        }

        return $allCommentsOk;
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
    private function extractInlineImageUrls(string $body, string $htmlBody): array
    {
        $urls = [];

        if (preg_match_all('/!\\[[^\\]]*\\]\\(\\s*(https:\\/\\/[^\\s)]+)\\s*\\)/iu', $body, $matches) > 0) {
            foreach ($matches[1] as $url) {
                $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if (preg_match_all("/<img\\b[^>]*\\bsrc=[\"'](https:\\/\\/[^\"']+)[\"'][^>]*>/iu", $htmlBody, $matches) > 0) {
            foreach ($matches[1] as $url) {
                $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return array_values(array_unique($urls));
    }

    private function attachmentFileNameFromUrl(string $url): string
    {
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
        if (
            $name === '' || $name === '/' || $name === '.'
            || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp',
                    'svg'], true)
        ) {
            $name = 'zendesk-inline-image.png';
        }

        return $this->safeFileName($name);
    }

    private function guessImageContentType(string $fileName): string
    {
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
    private function syncCommentAttachments(
        SyncJob $job,
        AuthConfig $auth,
        array $attachments,
        \CommonDBTM $item,
        ?string $body,
        int $authorId,
        string $date
    ): bool {
        if ($job->paginationConfig()->option('zendesk_download_attachments') !== true) {
            return true;
        }
        if (trim((string) ($item->fields['content'] ?? '')) !== '') {
            $body = null; // Link files without replacing a consolidated ticket/followup body.
        }
        return $this->syncNativeAttachments($job, $auth, $attachments, $item, $body, $authorId, $date);
    }

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
        $uploadEntity = (int) ($item->fields['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        if (
            $item instanceof \ITILFollowup
            && ($item->fields['itemtype'] ?? '') === \Ticket::class
            && (int) ($item->fields['items_id'] ?? 0) > 0
        ) {
            $parent = new \Ticket();
            if ($parent->getFromDB((int) $item->fields['items_id'])) {
                $uploadEntity = (int) ($parent->fields['entities_id'] ?? $uploadEntity);
            }
        }
        $allAttachmentsOk = true;
        $uploadFingerprints = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                return $this->failWith(
                    'ATTACHMENT_INVALID_DESCRIPTOR',
                    'Zendesk attachment metadata is not a valid object.'
                );
            }
            if (($attachment['deleted'] ?? false) === true) {
                // Zendesk explicitly marks a removed attachment: no URL can be recovered.
                continue;
            }
            if (($attachment['malware_scan_result'] ?? '') === 'malware_found') {
                return $this->failWith(
                    'ATTACHMENT_MALWARE',
                    'Zendesk attachment is flagged as malicious and was not downloaded.'
                );
            }

            $contentUrl = isset($attachment['content_url']) && is_string($attachment['content_url'])
               ? trim($attachment['content_url'])
               : '';
            if (
                $contentUrl === '' && isset($attachment['mapped_content_url']) && is_string(
                    $attachment['mapped_content_url']
                )
            ) {
                $contentUrl = trim($attachment['mapped_content_url']);
            }
            if ($contentUrl === '') {
                return $this->failWith(
                    'ATTACHMENT_URL_MISSING',
                    'Zendesk attachment metadata has no downloadable URL.'
                );
            }

            $rawAttachmentId = $attachment['id'] ?? null;
            $attachmentId = is_scalar($rawAttachmentId) && (string) $rawAttachmentId !== ''
               ? (string) $rawAttachmentId
               : hash('sha256', $contentUrl);
            $externalId = new ExternalId($attachmentId);

            $existingLink = $this->objectLinks->findByExternalId(
                $syncJob->authConfigReference(),
                'zendesk_attachment',
                $externalId,
            );

            $documentId = null;
            $tag = '';
            if ($existingLink !== null) {
                if ($existingLink->itemtype() !== TargetItemtype::DOCUMENT) {
                    return $this->failWith(
                        'ATTACHMENT_LINK_INVALID',
                        'Reconciliation points to an object other than a document.'
                    );
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
                        return $this->failWith(
                            'ATTACHMENT_DOCUMENT_TAG_MISSING',
                            'Reconciled GLPI document has no native tag.'
                        );
                    }
                    $docData[$documentId] = $document->fields;
                }
            }
            if ($existingLink === null) {
                $tag = $this->stableDocumentTag($syncJob, $attachmentId);
                // Repair documents created by GLPI before a worker was interrupted
                // between native upload and ObjectLink persistence.
                $orphan = new \Document();
                if ($orphan->getFromDBByCrit(['tag' => $tag])) {
                    $documentId = (int) $orphan->getID();
                    $docData[$documentId] = $orphan->fields;
                } else {
                    $fileName = $this->safeFileName((string) ($attachment['file_name'] ?? (
                                'zendesk-' . $attachmentId)));
                    $tempName = $this->downloadAttachmentToTemp($authConfig, $contentUrl, $fileName, $attachmentId);
                    if ($tempName === null) {
                        // A failed file must not discard earlier downloads or block
                        // unrelated attachments in the same Zendesk comment.
                        $allAttachmentsOk = false;
                        continue;
                    }
                    $tempPath = rtrim((string) GLPI_TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tempName;
                    $uploadFingerprints[$tag] = [
                        'sha1sum' => sha1_file($tempPath),
                        'entities_id' => $uploadEntity,
                        'is_blacklisted' => 0,
                    ];
                    $tempFiles[] = $tempName;
                    $uploadTags[] = $tag;
                }
            }

            $inline = ($attachment['inline'] ?? false) === true
               && str_starts_with(strtolower((string) ($attachment['content_type'] ?? '')), 'image/');

            if ($workingBody !== null && $inline) {
                [$workingBody, $replaced] = $this->replaceZendeskInlineImageWithTag(
                    $workingBody,
                    $attachment,
                    \Document::getImageTag($tag),
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
            return $allAttachmentsOk;
        }

        $input = [
            '_disablenotif' => 1,
            'users_id' => $authorId,
            'entities_id' => $uploadEntity,
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
        try {
            $processed = $item->addFiles($input, [
                'force_update' => true,
                'date' => $date,
            ]);
        } catch (\Throwable) {
            $this->cleanupTempFiles($tempFiles);
            return $this->failWith('ATTACHMENT_GLPI_UPLOAD_FAILED', 'GLPI native file upload raised an exception.');
        }
        if (!is_array($processed)) {
            $this->cleanupTempFiles($tempFiles);
            return $this->failWith(
                'ATTACHMENT_GLPI_UPLOAD_FAILED',
                'GLPI native file upload returned an invalid result.'
            );
        }

        // Resolve documents created by addFiles() using the tags we assigned.
        foreach ($attachmentRecords as &$record) {
            if ($record['document_id'] !== null) {
                continue;
            }
            $document = new \Document();
            $found = $document->getFromDBByCrit(['tag' => $record['tag']]);
            if (!$found && isset($uploadFingerprints[$record['tag']])) {
                // CommonDBTM::addFiles reuses identical bytes within the entity.
                // In that case the existing Document keeps its original tag.
                $fingerprint = $uploadFingerprints[$record['tag']];
                if (is_string($fingerprint['sha1sum'])) {
                    $found = $document->getFromDBByCrit($fingerprint);
                }
            }
            if (!$found) {
                $this->cleanupTempFiles($tempFiles);
                return $this->failWith(
                    'ATTACHMENT_GLPI_UPLOAD_FAILED',
                    'Native GLPI addFiles did not produce or reuse the expected Document; check allowed file '
                    . 'extensions and GLPI file permissions.'
                );
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
                    return $this->failWith(
                        'ATTACHMENT_INLINE_RENDER_FAILED',
                        'GLPI could not update inline image references.'
                    );
                }
            }
        }

        $syncJobId = $syncJob->id();
        if ($syncJobId === null) {
            return false;
        }
        foreach ($attachmentRecords as $record) {
            // A document may exist through a previous ticket/followup or after a
            // partial import. Reconcile its association for *this* item on replay.
            if (!$this->ensureDocumentItemLink((int) $record['document_id'], $item)) {
                return $this->failWith(
                    'ATTACHMENT_DOCUMENT_ASSOCIATION_FAILED',
                    'GLPI Document exists but cannot be linked to its ticket or followup.'
                );
            }
            $existing = $this->objectLinks->findByExternalId(
                $syncJob->authConfigReference(),
                'zendesk_attachment',
                $record['external_id'],
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
                'zendesk_attachment',
            ));
        }

        return $allAttachmentsOk;
    }

    /**
     * Replace Zendesk Markdown/HTML inline-image references with GLPI's native
     * #document-tag# token.  The token is later converted by addFiles().
     *
     * @param array<string, mixed> $attachment
     * @return array{0:string,1:bool}
     */
    private function replaceZendeskInlineImageWithTag(string $body, array $attachment, string $imageTag): array
    {
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
                    $body,
                ) ?? $body;
            }
        }

        return [$body, $replaced];
    }

    private function stableDocumentTag(SyncJob $syncJob, string $attachmentId): string
    {
        // Stable across retries, separated per source connection. No sensitive
        // URL, attachment filename or token is stored inside the tag.
        $hex = hash('sha256', 'uxconnector-zendesk-document-v1:'
           . $syncJob->authConfigReference()->value() . ':' . $attachmentId);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
           . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function ensureDocumentItemLink(int $documentId, \CommonDBTM $item): bool
    {
        $itemsId = (int) $item->getID();
        if ($itemsId <= 0 || $documentId <= 0) {
            return false;
        }
        $model = new \Document_Item();
        $criterion = [
            'documents_id' => $documentId,
            'itemtype' => $item::class,
            'items_id' => $itemsId,
        ];
        try {
            if ($model->find($criterion, [], 1) !== []) {
                return true;
            }
            return $model->add($criterion) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<string> $tempFiles */
    private function cleanupTempFiles(array $tempFiles): void
    {
        foreach ($tempFiles as $tempName) {
            $path = rtrim((string) GLPI_TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tempName;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function bodyWithoutInlineImages(string $body): string
    {
        $withoutImages = preg_replace('/!\\[[^\\]]*\\]\\(https?:\\/\\/[^)]+\\)/iu', '', $body);
        return trim($withoutImages ?? $body);
    }

    private function plainTextToGlpiHtml(string $text): string
    {
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
        // A unique temporary upload name is essential when different workers
        // process the same attachment concurrently. Never use remote filenames
        // as paths. Zendesk authorization must not be forwarded to CDN hosts.
        $tempName = 'uxc-zd-' . bin2hex(random_bytes(10)) . '-' . $fileName;
        $tempPath = rtrim((string) GLPI_TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tempName;
        $downloader = new ZendeskAttachmentDownloader($this->destinationPolicy);
        if ($downloader->download($contentUrl, $tempPath)) {
            return $tempName;
        }

        $directCode = $downloader->lastErrorCode();
        $current = $contentUrl;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $relative = $this->relativePathFromZendeskUrl($authConfig->endpoint()->value(), $current);
            if ($relative === '') {
                // CDN request is deliberately anonymous, with HTTPS and SSRF checks.
                if ($downloader->download($current, $tempPath)) {
                    return $tempName;
                }
                $this->failWith($downloader->lastErrorCode(), $downloader->lastErrorMessage());
                return null;
            }
            try {
                $response = $this->httpEngine->execute($authConfig, new HttpRequest(HttpMethod::GET, $relative));
                if ($response->isSuccessful()) {
                    $contentType = strtolower((string) $response->header('Content-Type'));
                    $looksHtml = preg_match('/^\s*(?:<!doctype\s+html|<html\b)/i', substr(
                        $response->body,
                        0,
                        512
                    )) === 1;
                    if (
                        (str_contains($contentType, 'text/html') || $looksHtml)
                        && !in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['html', 'htm'], true)
                    ) {
                        $this->failWith(
                            'ATTACHMENT_HTML_RESPONSE',
                            'Authenticated attachment fetch returned a login/HTML page instead of a file.'
                        );
                        return null;
                    }
                    if (strlen($response->body) > self::MAX_ATTACHMENT_BYTES) {
                        $this->failWith('ATTACHMENT_TOO_LARGE', 'Zendesk attachment exceeds the 50 MiB safety limit.');
                        return null;
                    }
                    $written = $response->body !== '' ? file_put_contents($tempPath, $response->body) : false;
                    if ($written === false || $written !== strlen($response->body)) {
                        @unlink($tempPath);
                        $this->failWith(
                            'ATTACHMENT_TEMP_WRITE_FAILED',
                            'GLPI temporary upload directory is unavailable or the downloaded attachment is '
                            . 'empty/incomplete.'
                        );
                        return null;
                    }
                    return $tempName;
                }
                if ($response->statusCode >= 300 && $response->statusCode < 400) {
                    $location = $response->header('Location');
                    if (!is_string($location) || $location === '') {
                        $this->failWith(
                            'ATTACHMENT_REDIRECT_MISSING',
                            'Authenticated attachment redirect omitted Location.'
                        );
                        return null;
                    }
                    $current = $downloader->resolveRedirectUrl($current, $location);
                    continue;
                }
                $this->failWith(
                    'ATTACHMENT_AUTH_HTTP_STATUS',
                    sprintf(
                        'Authenticated Zendesk attachment fetch returned HTTP %d (anonymous attempt: %s).',
                        $response->statusCode,
                        $directCode
                    )
                );
                return null;
            } catch (\Throwable) {
                $this->failWith(
                    'ATTACHMENT_AUTH_TRANSPORT',
                    'Authenticated Zendesk attachment fetch failed (transport; the generic API response limit '
                    . 'is 10 MiB).'
                );
                return null;
            }
        }
        $this->failWith(
            'ATTACHMENT_TOO_MANY_REDIRECTS',
            'Authenticated attachment exceeded the redirect safety limit.'
        );
        return null;
    }

    private function safeFileName(string $value): string
    {
        $value = basename(str_replace('\\', '/', trim($value)));
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '_', $value) ?? '';
        $value = preg_replace('/[^\pL\pN._()\[\] -]+/u', '_', $value) ?? '';
        $value = trim($value, " .\t\n\r\0\x0B");
        if ($value === '') {
            return 'zendesk-attachment.bin';
        }
        return mb_substr($value, 0, 180);
    }

    private function requesterType(): int
    {
        return defined('CommonITILActor::REQUESTER') ? \CommonITILActor::REQUESTER : 1;
    }

    private function assignType(): int
    {
        return defined('CommonITILActor::ASSIGN') ? \CommonITILActor::ASSIGN : 2;
    }

    private function toGlpiDate(string $value): string
    {
        if ($value === '') {
            return date('Y-m-d H:i:s');
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return date('Y-m-d H:i:s');
        }
    }

    private function normalizeText(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return $value ?? '';
    }

    private function relativePathFromZendeskUrl(string $endpoint, string $url): string
    {
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
