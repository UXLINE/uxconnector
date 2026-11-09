<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Zendesk;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/** Reconciles one Zendesk topic into GLPI and exposes the resulting write action. */
interface ZendeskTopicCategoryWriterInterface
{
    public function ensureForTopicWithOutcome(
        SyncJob $syncJob,
        string $topicValue,
        string $label
    ): ?ZendeskTopicCategoryOutcome;
}
