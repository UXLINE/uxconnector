<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketClassification;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketInput;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Enum\SemanticTicketClass;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticClassificationProviderInterface;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticTicketSourceInterface;

/**
 * Engine-side semantic analysis.
 *
 * The LLM/provider returns one classification per ticket. UXConnector owns all
 * aggregation, percentages, coverage, confidence-threshold application
 * (through the provider), and declared-vs-semantic comparison metrics.
 */
final readonly class AnalyzeSemanticTicketClassificationService
{
    public function __construct(
        private SemanticTicketSourceInterface $source,
        private SemanticClassificationProviderInterface $classifier,
        private SemanticEngineConfig $config,
        private string $providerContract = 'openai-compatible-chat-completions'
    ) {
        if (!$this->config->isUsable()) {
            throw new \RuntimeException(
                'Semantic engine configuration must be active before analysis.',
            );
        }
    }

    /**
     * @param array<string,mixed> $criteria
     * @return array<string,mixed>
     */
    public function execute(array $criteria): array
    {
        $sourceResult = $this->source->fetch($criteria);

        $inputsById = [];
        $providerInputs = [];
        $localResults = [];

        foreach ($sourceResult->tickets as $ticket) {
            $inputsById[$ticket->ticketId] = $ticket;

            if (trim($ticket->title) === ''
                 && trim($ticket->description) === ''
            ) {
                $localResults[$ticket->ticketId] = new SemanticTicketClassification(
                    $ticket->ticketId,
                    SemanticTicketClass::UNCERTAIN,
                    SemanticTicketClass::UNCERTAIN,
                    0.0,
                    'no_text_evidence',
                    false,
                );
                continue;
            }

            $providerInputs[] = $ticket;
        }

        $providerResults = $this->classifier->classify($providerInputs);

        $resultsById = $localResults;

        foreach ($providerResults as $classification) {
            if (isset($resultsById[$classification->ticketId])) {
                throw new \RuntimeException(sprintf(
                    'Duplicate semantic classification for ticket %d.',
                    $classification->ticketId,
                ));
            }

            if (!isset($inputsById[$classification->ticketId])) {
                throw new \RuntimeException(sprintf(
                    'Semantic provider returned ticket %d outside the selected source scope.',
                    $classification->ticketId,
                ));
            }

            $resultsById[$classification->ticketId] = $classification;
        }

        if (count($resultsById) !== $sourceResult->selectedCount) {
            throw new \RuntimeException(sprintf(
                'Semantic analysis produced %d results for %d selected tickets.',
                count($resultsById),
                $sourceResult->selectedCount,
            ));
        }

        $orderedResults = [];

        foreach ($sourceResult->tickets as $ticket) {
            if (!isset($resultsById[$ticket->ticketId])) {
                throw new \RuntimeException(sprintf(
                    'Semantic analysis is missing ticket %d.',
                    $ticket->ticketId,
                ));
            }

            $orderedResults[] = $resultsById[$ticket->ticketId];
        }

        return $this->aggregate(
            $criteria,
            $sourceResult->candidateCount,
            $sourceResult->selectedCount,
            $sourceResult->selectionTruncated,
            $sourceResult->sourceScanTruncated,
            $sourceResult->textTruncatedTickets,
            $sourceResult->tickets,
            $providerResults,
            $orderedResults,
        );
    }

    /**
     * @param array<string,mixed> $criteria
     * @param list<SemanticTicketInput> $tickets
     * @param list<SemanticTicketClassification> $providerResults
     * @param list<SemanticTicketClassification> $results
     * @return array<string,mixed>
     */
    private function aggregate(
        array $criteria,
        int $candidateCount,
        int $selectedCount,
        bool $selectionTruncated,
        bool $sourceScanTruncated,
        int $textTruncatedTickets,
        array $tickets,
        array $providerResults,
        array $results
    ): array {
        $classCounts = array_fill_keys(SemanticTicketClass::values(), 0);
        $predictedClassCounts = array_fill_keys(SemanticTicketClass::values(), 0);

        $declaredTypes = [];
        $incidentSemanticCounts = array_fill_keys(SemanticTicketClass::values(), 0);

        $ticketById = [];
        foreach ($tickets as $ticket) {
            $ticketById[$ticket->ticketId] = $ticket;
            $declaredType = $ticket->declaredType ?? '(Non renseigné)';
            $declaredTypes[$declaredType] = ($declaredTypes[$declaredType] ?? 0) + 1;
        }

        $thresholdAppliedCount = 0;
        $confidentCount = 0;

        foreach ($results as $classification) {
            $classCounts[$classification->semanticClass->value]++;
            $predictedClassCounts[$classification->predictedClass->value]++;

            if ($classification->thresholdApplied) {
                $thresholdAppliedCount++;
            }

            if ($classification->semanticClass !== SemanticTicketClass::UNCERTAIN) {
                $confidentCount++;
            }

            $ticket = $ticketById[$classification->ticketId] ?? null;
            if ($ticket instanceof SemanticTicketInput && $ticket->declaredType === 'Incident') {
                $incidentSemanticCounts[$classification->semanticClass->value]++;
            }
        }

        $declaredIncidentCount = (int) ($declaredTypes['Incident'] ?? 0);
        $declaredIncidentUncertain = (int) $incidentSemanticCounts[SemanticTicketClass::UNCERTAIN->value];
        $declaredIncidentConfident = max(
            0,
            $declaredIncidentCount - $declaredIncidentUncertain,
        );

        $likelyUserAssistance = (int) $incidentSemanticCounts[SemanticTicketClass::USER_ASSISTANCE->value];

        $providerConfidenceValues = array_map(
            static fn(SemanticTicketClassification $item): float => $item->confidence,
            $providerResults,
        );

        $averageProviderConfidence = $providerConfidenceValues === []
            ? null
            : round(array_sum($providerConfidenceValues) / count($providerConfidenceValues), 4);

        $confidenceAtOrAboveThreshold = 0;
        $confidenceBelowThreshold = 0;
        $providerPredictedUncertain = 0;

        foreach ($providerResults as $classification) {
            if ($classification->confidence >= $this->config->confidenceThreshold()) {
                $confidenceAtOrAboveThreshold++;
            } else {
                $confidenceBelowThreshold++;
            }

            if ($classification->predictedClass === SemanticTicketClass::UNCERTAIN) {
                $providerPredictedUncertain++;
            }
        }

        $semanticDistribution = [];
        foreach (SemanticTicketClass::cases() as $class) {
            $count = (int) $classCounts[$class->value];
            $semanticDistribution[] = [
                'class' => $class->value,
                'count' => $count,
                'percentage' => $this->percent($count, $selectedCount),
            ];
        }

        $declaredTypeDistribution = [];
        ksort($declaredTypes);

        foreach ($declaredTypes as $label => $count) {
            $declaredTypeDistribution[] = [
                'declared_type' => $label,
                'count' => (int) $count,
                'percentage' => $this->percent((int) $count, $selectedCount),
            ];
        }

        $detailLimit = max(0, min(500, (int) ($criteria['result_limit'] ?? 100)));
        $ticketClassifications = [];

        foreach (array_slice($results, 0, $detailLimit) as $classification) {
            $ticket = $ticketById[$classification->ticketId];

            $ticketClassifications[] = [
                'ticket_id' => $classification->ticketId,
                'declared_type' => $ticket->declaredType,
                'itil_category' => $ticket->category,
                'class' => $classification->semanticClass->value,
                'predicted_class' => $classification->predictedClass->value,
                'confidence' => $classification->confidence,
                'reason_code' => $classification->reasonCode,
                'threshold_applied' => $classification->thresholdApplied,
            ];
        }

        $scopeComplete = !$selectionTruncated && !$sourceScanTruncated;

        return [
            'success' => true,
            'status' => 'analysis_complete',
            'period' => [
                'date_from' => (string) ($criteria['date_from'] ?? ''),
                'date_to' => (string) ($criteria['date_to'] ?? ''),
            ],
            'scope' => [
                'entity_id' => isset($criteria['entity_id']) ? (int) $criteria['entity_id'] : null,
                'category_id' => isset($criteria['category_id']) ? (int) $criteria['category_id'] : null,
                'declared_type_filter' => trim((string) ($criteria['type'] ?? '')) !== ''
                    ? (string) $criteria['type']
                    : null,
                'candidate_tickets' => $candidateCount,
                'selected_tickets' => $selectedCount,
                'scope_complete' => $scopeComplete,
                'max_tickets' => max(1, min(10000, (int) ($criteria['max_tickets'] ?? 500))),
            ],
            'declaredTypeDistribution' => $declaredTypeDistribution,
            'semanticClassification' => $semanticDistribution,
            'declaredIncidentSemanticClassification' => array_map(
                fn(SemanticTicketClass $class): array => [
                    'class' => $class->value,
                    'count' => (int) $incidentSemanticCounts[$class->value],
                    'percentage_of_declared_incidents' => $this->percent(
                        (int) $incidentSemanticCounts[$class->value],
                        $declaredIncidentCount,
                    ),
                ],
                SemanticTicketClass::cases(),
            ),
            'misclassification' => [
                'declared_incidents' => $declaredIncidentCount,
                'declared_incidents_confidently_classified' => $declaredIncidentConfident,
                'likely_user_assistance' => $likelyUserAssistance,
                'likely_user_assistance_percent_of_declared_incidents' => $this->percent(
                    $likelyUserAssistance,
                    $declaredIncidentCount,
                ),
                'likely_user_assistance_percent_of_confidently_classified_declared_incidents' => $this->percent(
                    $likelyUserAssistance,
                    $declaredIncidentConfident,
                ),
            ],
            'confidence' => [
                'threshold' => $this->config->confidenceThreshold(),
                'provider_average_confidence' => $averageProviderConfidence,
                'provider_at_or_above_threshold' => $confidenceAtOrAboveThreshold,
                'provider_below_threshold' => $confidenceBelowThreshold,
                'threshold_applied_count' => $thresholdAppliedCount,
                'provider_predicted_uncertain' => $providerPredictedUncertain,
            ],
            'dataQuality' => [
                'candidate_tickets' => $candidateCount,
                'selected_tickets' => $selectedCount,
                'selection_truncated' => $selectionTruncated,
                'source_scan_truncated' => $sourceScanTruncated,
                'scope_complete' => $scopeComplete,
                'tickets_with_text' => count($providerResults),
                'tickets_without_text' => $selectedCount - count($providerResults),
                'text_truncated_tickets' => $textTruncatedTickets,
                'provider_results' => count($providerResults),
                'effective_confident_classifications' => $confidentCount,
                'effective_uncertain_classifications' => (int) $classCounts[SemanticTicketClass::UNCERTAIN->value],
                'classification_coverage_percent' => $this->percent(count($results), $selectedCount),
                'confident_classification_coverage_percent' => $this->percent($confidentCount, $selectedCount),
            ],
            'engine' => [
                'provider_contract' => $this->providerContract,
                'model' => $this->config->model(),
                'classifier_version' => $this->config->classifierVersion(),
                'confidence_threshold' => $this->config->confidenceThreshold(),
                'temperature' => $this->config->temperature(),
                'batch_size' => $this->config->batchSize(),
            ],
            'ticketClassifications' => $ticketClassifications,
            'ticketClassificationsReturned' => count($ticketClassifications),
            'metricDefinitions' => [
                'declared_type' => 'GLPI ticket type stored in glpi_tickets.type. It is not changed by semantic classification.',
                'itil_category' => 'Registered GLPI ITIL category path supplied as contextual metadata. It is not the semantic result.',
                'semantic_classification' => 'UXConnector engine classification calculated from ticket title/content through the configured semantic provider.',
                'uncertain' => 'Effective class used when the provider itself returns uncertain, when text evidence is absent, or when provider confidence is below the configured threshold.',
                'likely_user_assistance_percent_of_declared_incidents' => 'Engine-calculated share of selected GLPI-declared Incident tickets whose effective semantic class is user_assistance. It is not inferred by Copilot.',
                'scope_complete' => 'True only when neither max_tickets selection nor the synchronous source scan safety cap truncated the matching scope.',
            ],
            'dataSources' => [
                'glpi' => true,
                'semantic_provider' => count($providerResults) > 0,
            ],
        ];
    }

    private function percent(int $numerator, int $denominator): ?float
    {
        return $denominator > 0
            ? round(($numerator / $denominator) * 100, 1)
            : null;
    }
}
