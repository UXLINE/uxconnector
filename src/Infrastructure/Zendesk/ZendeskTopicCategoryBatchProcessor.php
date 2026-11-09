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

use GlpiPlugin\Uxconnector\Application\Sync\DTO\BatchProcessResult;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\InboundBatchProcessorInterface;
use GlpiPlugin\Uxconnector\Application\Sync\PostProcessing\StandaloneInboundProcessorInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/**
 * Scans the non-closed Zendesk ticket stream and materializes only the topic
 * categories actually used by the migration scope.
 */
final class ZendeskTopicCategoryBatchProcessor implements InboundBatchProcessorInterface, StandaloneInboundProcessorInterface
{
    private const TEMPLATE_ID = 'zendesk.itilcategories.bootstrap';

    public function __construct(
        private readonly ZendeskTopicCatalogInterface $catalog,
        private readonly ZendeskTopicCategoryWriterInterface $categories
    ) {}

    public function supports(SyncJob $syncJob): bool
    {
        return $syncJob->templateId() === self::TEMPLATE_ID;
    }

    /**
     * Runs against Zendesk ticket-field OPTIONS, not tickets. An existing
     * persisted bootstrap job is transparently routed here by the executor.
     */
    public function processStandalone(SyncJob $syncJob): BatchProcessResult
    {
        $options = $this->catalog->topicOptionsForJob($syncJob);
        if ($options === []) {
            throw new \RuntimeException('Zendesk Sujet catalog is empty or unavailable; refusing an empty successful bootstrap.');
        }

        return $this->writeTopics($syncJob, $options, true);
    }

    public function process(SyncJob $syncJob, array $items): BatchProcessResult
    {
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

        $labels = [];
        foreach (array_keys($topicValues) as $topicValue) {
            $label = $this->catalog->labelForValue($syncJob, $topicValue);
            if ($label === null) {
                $labels[$topicValue] = '';
                continue;
            }
            $labels[$topicValue] = $label;
        }
        return $this->writeTopics($syncJob, $labels);
    }

    /** @param array<string,string> $topics */
    private function writeTopics(SyncJob $syncJob, array $topics, bool $failFast = false): BatchProcessResult
    {
        $processed = $failed = $created = $updated = $skipped = 0;
        foreach ($topics as $topicValue => $label) {
            if ($label === '') {
                if ($failFast) {
                    throw new \RuntimeException('Zendesk Sujet option has no label: ' . substr($topicValue, 0, 120));
                }
                $failed++;
                continue;
            }
            $outcome = $this->categories->ensureForTopicWithOutcome($syncJob, $topicValue, $label);
            if ($outcome === null) {
                if ($failFast) {
                    throw new \RuntimeException('Failed to reconcile Zendesk Sujet: ' . substr($topicValue, 0, 120));
                }
                $failed++;
                continue;
            }
            $processed++;
            match ($outcome->action) {
                'create' => $created++,
                'update' => $updated++,
                'skip' => $skipped++,
                default => null,
            };
        }
        return new BatchProcessResult($processed, $failed, $created, $updated, $skipped, 0);
    }
}
