<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Zendesk;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/** Read-only topic catalog contract used by the Zendesk category batch adapter. */
interface ZendeskTopicCatalogInterface
{
    /** @return array<string,string> Stable Zendesk tag => full hierarchical label. */
    public function topicOptionsForJob(SyncJob $syncJob): array;

    /** @param array<string,mixed> $ticket */
    public function topicValueFromTicket(SyncJob $syncJob, array $ticket): ?string;

    public function labelForValue(SyncJob $syncJob, string $value): ?string;
}
