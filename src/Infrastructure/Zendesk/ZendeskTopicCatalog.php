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
use GlpiPlugin\Uxconnector\Application\Sync\Exception\TransientInfrastructureException;

/**
 * Discovers the Zendesk "Sujet" / topic tagger field without hard-coding a
 * customer-specific field id, then exposes its technical-value -> label map.
 *
 * The field is identified by tagger options whose value starts with `topic__`.
 * This makes the discovery independent from the Zendesk UI language and from
 * the numeric custom-field id used by a particular tenant.
 */
final class ZendeskTopicCatalog implements ZendeskTopicCatalogInterface
{
    private const MAX_FIELD_PAGES = 20;
    private const MAX_OPTION_PAGES = 30;

    /** @return array<string,string> */
    public function topicOptionsForJob(SyncJob $syncJob): array
    {
        return $this->forJob($syncJob)['labels'] ?? [];
    }


    /**
     * @var array<string, array{field_id:string, labels:array<string,string>}>
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
    public function forJob(SyncJob $syncJob): ?array
    {
        $reference = $syncJob->authConfigReference()->value();
        $configuredFieldId = $syncJob->paginationConfig()->option('zendesk_topic_field_id');
        $cacheKey = (string) $reference . ':' . (string) ($configuredFieldId ?? 'auto');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $authConfig = $this->authConfigs->findById(new AuthConfigId($reference));
        if ($authConfig === null) {
            return null;
        }

        // Optional tenant-owned selection, stored on this JOB rather than in code
        // or in the shared authentication connection. Existing jobs remain
        // auto-discovered unless the operator explicitly supplies the ID.
        if ($configuredFieldId !== null && $configuredFieldId !== '') {
            if (
                (!is_string($configuredFieldId) && !is_int($configuredFieldId))
                || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $configuredFieldId)
            ) {
                throw new \RuntimeException(
                    'Invalid zendesk_topic_field_id: expected a positive Zendesk ticket field ID.'
                );
            }
            $fieldId = (string) $configuredFieldId;
            // A successful OPTIONS response alone does not prove that a given
            // field is a tagger. Validate its definition before importing.
            $definition = $this->fetchJson($authConfig, '/api/v2/ticket_fields/' . $fieldId . '.json');
            $field = $definition['ticket_field'] ?? null;
            if (
                !is_array($field)
                || (string) ($field['id'] ?? '') !== $fieldId
                || strtolower((string) ($field['type'] ?? '')) !== 'tagger'
            ) {
                throw new \RuntimeException('Configured Zendesk Sujet field is not an accessible tagger field (ID '
                    . $fieldId . ').');
            }
            return $this->loadOptionsForField($authConfig, $cacheKey, $fieldId, true);
        }

        // Discovery is cheap: list ticket-field DEFINITIONS, never search tickets.
        $path = '/api/v2/ticket_fields.json?per_page=100';
        $candidates = [];
        $visited = [];
        $page = 0;

        while ($path !== '') {
            if (++$page > self::MAX_FIELD_PAGES || isset($visited[$path])) {
                throw new \RuntimeException('Zendesk ticket-field pagination limit or loop detected.');
            }
            $visited[$path] = true;
            $payload = $this->fetchJson($authConfig, $path);
            $fields = $payload['ticket_fields'] ?? null;
            if (!is_array($fields)) {
                throw new \RuntimeException('Zendesk ticket_fields response has no ticket_fields array.');
            }

            foreach ($fields as $field) {
                if (
                    !is_array($field) || !isset($field['id']) || !is_scalar($field['id']) || !ctype_digit(
                        (string) $field['id']
                    )
                ) {
                    continue;
                }
                if (strtolower((string) ($field['type'] ?? '')) !== 'tagger') {
                    continue;
                }
                $labels = self::extractTopicLabels($field['custom_field_options'] ?? []);
                $title = strtolower(trim((string) ($field['title'] ?? '')));
                // A field may not include all options in its list response;
                // a named Sujet field remains a candidate even with no inline options.
                if ($labels !== [] || in_array($title, ['sujet', 'subject', 'topic'], true)) {
                    $candidates[] = [
                        'field_id' => (string) $field['id'],
                        'title' => $title,
                        'labels' => $labels,
                    ];
                }
            }

            $path = $this->nextPath($payload, '/api/v2/ticket_fields');
        }

        if ($candidates === []) {
            return null;
        }
        $named = array_values(array_filter($candidates, static fn(array $c): bool => in_array(
            $c['title'],
            ['sujet', 'subject', 'topic'],
            true
        )));
        // Fail closed on ambiguous Zendesk fields (even if some values overlap).
        if (count($named) === 1) {
            $selected = $named[0];
        } elseif ($named === [] && count($candidates) === 1) {
            $selected = $candidates[0];
        } else {
            return null;
        }

        return $this->loadOptionsForField($authConfig, $cacheKey, $selected['field_id'], false);
    }

    /**
     * @return array{field_id:string, labels:array<string,string>}|null
     */
    private function loadOptionsForField(
        \GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig $authConfig,
        string $cacheKey,
        string $fieldId,
        bool $explicitSelection
    ): ?array {
        // Query the actual ticket-field OPTIONS endpoint, whose paginated
        // records contain the stable technical tag and the human label.
        $optionPath = '/api/v2/ticket_fields/' . $fieldId . '/options?page[size]=100';
        $visited = [];
        $page = 0;
        $labels = [];
        $rawOptions = 0;
        $expectedPrefix = '/api/v2/ticket_fields/' . $fieldId . '/options';
        while ($optionPath !== '') {
            if (++$page > self::MAX_OPTION_PAGES || isset($visited[$optionPath])) {
                throw new \RuntimeException('Zendesk Sujet options pagination limit or loop detected.');
            }
            $visited[$optionPath] = true;
            $payload = $this->fetchJson($authConfig, $optionPath);
            if (!is_array($payload['custom_field_options'] ?? null)) {
                throw new \RuntimeException('Zendesk Sujet options response lacks custom_field_options.');
            }
            $rawOptions += count($payload['custom_field_options']);
            // Only the explicitly operator-selected field can admit tags without
            // the legacy 'topic__' prefix. Auto discovery stays strict, keeping
            // unrelated Zendesk fields out of the ITIL category catalogue.
            $pageLabels = $explicitSelection
               ? self::extractLabelsFromConfirmedField($payload['custom_field_options'])
               : self::extractTopicLabels($payload['custom_field_options']);
            foreach ($pageLabels as $tag => $label) {
                if (isset($labels[$tag]) && $labels[$tag] !== $label) {
                    throw new \RuntimeException('Zendesk Sujet catalog contains conflicting topic tags.');
                }
                $labels[$tag] = $label;
            }
            $optionPath = $this->nextPath($payload, $expectedPrefix);
        }

        if ($labels === []) {
            // Prevent a silent all-clear after accidental wrong field discovery.
            if ($explicitSelection) {
                throw new \RuntimeException('Configured Zendesk Sujet field ID ' . $fieldId . ' returned '
                   . $rawOptions . ' options but no usable label/tag pairs; refusing an empty catalogue.');
            }
            return null;
        }
        $catalog = ['field_id' => $fieldId, 'labels' => $labels];
        $this->cache[$cacheKey] = $catalog;
        return $catalog;
    }

    /** @param mixed $rawOptions @return array<string,string> */
    public static function extractTopicLabels(mixed $rawOptions): array
    {
        if (!is_array($rawOptions)) {
            return [];
        }
        $labels = [];
        foreach ($rawOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            if (!is_scalar($option['value'] ?? null) || !is_scalar($option['name'] ?? null)) {
                continue;
            }
            $tag = trim((string) $option['value']);
            $label = trim((string) $option['name']);
            if (str_starts_with($tag, 'topic__') && $label !== '') {
                $labels[$tag] = $label;
            }
        }
        return $labels;
    }

    /**
     * Only used after a validated explicit field selection. Zendesk option
     * values are stable technical tags even when tenants do not use topic__.
     *
     * @param mixed $rawOptions
     * @return array<string,string>
     */
    public static function extractLabelsFromConfirmedField(mixed $rawOptions): array
    {
        if (!is_array($rawOptions)) {
            return [];
        }
        $labels = [];
        foreach ($rawOptions as $option) {
            if (
                !is_array($option)
                || !is_string($option['value'] ?? null)
                || !is_string($option['name'] ?? null)
            ) {
                continue;
            }
            $tag = trim($option['value']);
            $label = trim($option['name']);
            if (
                $tag === '' || $label === '' || strlen($tag) > 255
                || !preg_match('/^[a-zA-Z0-9_:\-]+$/D', $tag)
            ) {
                continue;
            }
            if (isset($labels[$tag]) && $labels[$tag] !== $label) {
                throw new \RuntimeException('Configured Zendesk Sujet field has conflicting labels for a tag.');
            }
            $labels[$tag] = $label;
        }
        return $labels;
    }

    /** @return array<string,mixed> */
    private function fetchJson(\GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig $config, string $path): array
    {
        $response = $this->httpEngine->execute($config, new HttpRequest(HttpMethod::GET, $path));
        if (!$response->isSuccessful()) {
            if (in_array($response->statusCode, [408, 429], true) || $response->statusCode >= 500) {
                throw new TransientInfrastructureException(
                    'Zendesk topic catalog temporarily unavailable (HTTP ' . $response->statusCode . ').'
                );
            }
            throw new \RuntimeException('Zendesk topic catalog unavailable (HTTP ' . $response->statusCode . ').');
        }
        $payload = $response->json();
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function nextPath(array $payload, string $expectedPathPrefix): string
    {
        $next = $payload['links']['next'] ?? $payload['next_page'] ?? null;
        if ($next === null || $next === '') {
            if (($payload['meta']['has_more'] ?? false) === true) {
                throw new \RuntimeException('Zendesk pagination indicates more pages but no next link.');
            }
            return '';
        }
        if (!is_string($next)) {
            throw new \RuntimeException('Invalid Zendesk pagination link.');
        }
        $parts = parse_url($next);
        if ($parts === false || !isset($parts['path'])) {
            throw new \RuntimeException('Invalid Zendesk pagination URI.');
        }
        $path = (string) $parts['path'];
        // Follow only the same API resource. No arbitrary URL from a remote
        // payload is ever requested, mitigating redirect/path-injection risks.
        if ($path !== $expectedPathPrefix && !str_starts_with($path, $expectedPathPrefix . '.json')) {
            throw new \RuntimeException('Zendesk pagination attempted to leave the allowed catalog resource.');
        }
        return $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }

    /** @param array<string, mixed> $ticket */
    public function topicValueFromTicket(SyncJob $syncJob, array $ticket): ?string
    {
        $catalog = $this->forJob($syncJob);
        if ($catalog === null) {
            return null;
        }

        $customFields = $ticket['custom_fields'] ?? null;
        if (is_array($customFields)) {
            foreach ($customFields as $customField) {
                if (!is_array($customField) || (string) ($customField['id'] ?? '') !== $catalog['field_id']) {
                    continue;
                }
                $value = $customField['value'] ?? null;
                if (!is_scalar($value)) {
                    return null;
                }
                $topic = trim((string) $value);
                return $topic !== '' ? $topic : null;
            }
        }

        // Search Export and some historical ticket payloads omit custom_fields.
        // Only fall back to ticket tags if EXACTLY ONE known catalog value is
        // present; otherwise refuse to guess the ITIL category.
        $tags = $ticket['tags'] ?? null;
        if (!is_array($tags)) {
            return null;
        }
        $matches = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && array_key_exists($tag, $catalog['labels'])) {
                $matches[$tag] = true;
            }
        }
        return count($matches) === 1 ? (string) array_key_first($matches) : null;
    }

    public function labelForValue(SyncJob $syncJob, string $value): ?string
    {
        $catalog = $this->forJob($syncJob);
        if ($catalog === null) {
            return null;
        }

        return $catalog['labels'][$value] ?? null;
    }
}
