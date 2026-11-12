<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Zendesk;

use GlpiPlugin\Uxconnector\Application\Sync\Retry\RetrySourceRecordRefresherInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncFailure;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/**
 * Reloads one Zendesk object by its immutable Zendesk id before a failed-item
 * retry. The original failure snapshot remains audit evidence only.
 *
 * Paths are built from a closed allow-list; no URL stored in a failure snapshot
 * is ever executed, preventing a stored-data SSRF primitive.
 */
final class ZendeskRetrySourceRecordRefresher implements RetrySourceRecordRefresherInterface
{
    public function __construct(
        private readonly AuthConfigRepositoryInterface $authConfigs,
        private readonly HttpEngineInterface $httpEngine
    ) {
    }

    public function refresh(SyncJob $syncJob, SyncFailure $failure): ?array
    {
        $spec = $this->resolveSpec($syncJob, $failure);
        if ($spec === null) {
            return null;
        }

        $sourceId = trim((string) $failure->sourceId);
        if ($sourceId === '' || !preg_match('/^[0-9]+$/', $sourceId)) {
            throw new \RuntimeException('Zendesk failed-item retry requires a numeric source identifier.');
        }

        $authConfig = $this->authConfigs->findById(
            new AuthConfigId($syncJob->authConfigReference()->value()),
        );
        if ($authConfig === null || !$authConfig->isUsable()) {
            throw new \RuntimeException('Zendesk authentication configuration is unavailable for source refresh.');
        }
        if ($authConfig->connectorId() !== 'zendesk') {
            throw new \RuntimeException('Zendesk source refresh requires a Zendesk authentication configuration.');
        }

        [$resource, $wrapper] = $spec;
        $path = sprintf('/api/v2/%s/%s.json', $resource, rawurlencode($sourceId));
        $response = $this->httpEngine->execute($authConfig, new HttpRequest(HttpMethod::GET, $path));

        if (!$response->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Unable to refresh Zendesk source object %s #%s (HTTP %d).',
                $failure->sourceType,
                $sourceId,
                $response->statusCode,
            ));
        }

        $payload = $response->json();
        $record = $payload[$wrapper] ?? null;
        if (!is_array($record)) {
            throw new \RuntimeException(sprintf(
                'Zendesk source refresh response does not contain expected "%s" object.',
                $wrapper,
            ));
        }

        return $record;
    }

    /** @return array{0:string,1:string}|null */
    private function resolveSpec(SyncJob $syncJob, SyncFailure $failure): ?array
    {
        $templateId = $syncJob->templateId() ?? '';
        if (!str_starts_with($templateId, 'zendesk.')) {
            return null;
        }

        return match ($failure->sourceType) {
            'zendesk_user' => ['users', 'user'],
            'zendesk_group' => ['groups', 'group'],
            'zendesk_organization' => ['organizations', 'organization'],
            'zendesk_ticket_form' => ['ticket_forms', 'ticket_form'],
            'zendesk_topic', 'ticket' => ['tickets', 'ticket'],
            default => null,
        };
    }
}
