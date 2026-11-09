<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticTicketSourceInterface;

final readonly class PrepareSemanticTicketClassificationService
{
    public function __construct(private SemanticTicketSourceInterface $source) {}

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    public function execute(array $criteria): array
    {
        $criteria = SemanticPreparationFingerprint::normalizeCriteria($criteria);
        $sourceResult = $this->source->fetch($criteria);

        $responseCriteria = $criteria;

        if ($responseCriteria['entity_id'] === null) {
            unset($responseCriteria['entity_id']);
        }

        if ($responseCriteria['category_id'] === null) {
            unset($responseCriteria['category_id']);
        }

        $tickets = [];
        $ticketsWithoutText = 0;

        foreach ($sourceResult->tickets as $ticket) {
            if (trim($ticket->title) === '' && trim($ticket->description) === '') {
                $ticketsWithoutText++;
                continue;
            }
            $tickets[] = $ticket->toProviderArray();
        }

        $scopeComplete = !$sourceResult->selectionTruncated
            && !$sourceResult->sourceScanTruncated;

        return [
            'success' => true,
            'status' => 'classification_required',
            'classifier_version' => PowerPlatformSemanticPolicy::CLASSIFIER_VERSION,
            'confidence_threshold' => PowerPlatformSemanticPolicy::CONFIDENCE_THRESHOLD,
            'scope_fingerprint' => SemanticPreparationFingerprint::calculate($criteria, $sourceResult),
            'criteria' => $responseCriteria,
            'scope' => [
                'candidate_tickets' => $sourceResult->candidateCount,
                'selected_tickets' => $sourceResult->selectedCount,
                'tickets_sent_to_llm' => count($tickets),
                'scope_complete' => $scopeComplete,
                'max_tickets' => $criteria['max_tickets'],
            ],
            'tickets' => $tickets,
            'dataQuality' => [
                'candidate_tickets' => $sourceResult->candidateCount,
                'selected_tickets' => $sourceResult->selectedCount,
                'selection_truncated' => $sourceResult->selectionTruncated,
                'source_scan_truncated' => $sourceResult->sourceScanTruncated,
                'scope_complete' => $scopeComplete,
                'tickets_with_text' => count($tickets),
                'tickets_without_text' => $ticketsWithoutText,
                'text_truncated_tickets' => $sourceResult->textTruncatedTickets,
            ],
        ];
    }
}
