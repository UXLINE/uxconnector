<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Adapter;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketClassification;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketInput;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Enum\SemanticTicketClass;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticClassificationProviderInterface;

final class PrecomputedSemanticClassificationProvider implements SemanticClassificationProviderInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $rowsByTicketId = [];

    /**
     * @param list<mixed> $rows
     */
    public function __construct(
        array $rows,
        private readonly float $confidenceThreshold
    ) {
        if ($confidenceThreshold < 0.0 || $confidenceThreshold > 1.0) {
            throw new \InvalidArgumentException('Semantic confidence threshold must be between 0 and 1.');
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Each semantic classification must be an object.');
            }

            $ticketId = $this->ticketId($row);

            if (isset($this->rowsByTicketId[$ticketId])) {
                throw new \InvalidArgumentException("Duplicate semantic classification for ticket {$ticketId}.");
            }

            $this->rowsByTicketId[$ticketId] = $row;
        }
    }

    public function classify(array $tickets): array
    {
        $expected = [];

        foreach ($tickets as $ticket) {
            if (!$ticket instanceof SemanticTicketInput) {
                throw new \InvalidArgumentException('Expected SemanticTicketInput objects.');
            }
            $expected[$ticket->ticketId] = true;
        }

        if (count($expected) !== count($this->rowsByTicketId)) {
            throw new \RuntimeException(sprintf(
                'Power Platform returned %d classifications for %d tickets requiring LLM classification.',
                count($this->rowsByTicketId),
                count($expected),
            ));
        }

        $results = [];

        foreach ($tickets as $ticket) {
            $row = $this->rowsByTicketId[$ticket->ticketId] ?? null;
            if (!is_array($row)) {
                throw new \RuntimeException("Power Platform classification is missing ticket {$ticket->ticketId}.");
            }
            $results[] = $this->classificationFromRow($row);
        }

        foreach (array_keys($this->rowsByTicketId) as $ticketId) {
            if (!isset($expected[$ticketId])) {
                throw new \RuntimeException("Power Platform returned ticket {$ticketId} outside the prepared scope.");
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function classificationFromRow(array $row): SemanticTicketClassification
    {
        foreach (['ticket_id', 'class', 'confidence', 'reason_code'] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new \InvalidArgumentException("Semantic classification is missing field {$field}.");
            }
        }

        $ticketId = $this->ticketId($row);

        if (!is_string($row['class'])) {
            throw new \InvalidArgumentException('Semantic class must be a string.');
        }

        $predictedClass = SemanticTicketClass::tryFrom(trim($row['class']));
        if ($predictedClass === null) {
            throw new \InvalidArgumentException('Unsupported semantic class.');
        }

        if (!is_int($row['confidence']) && !is_float($row['confidence'])) {
            throw new \InvalidArgumentException('Semantic confidence must be a JSON number.');
        }

        $confidence = (float) $row['confidence'];
        if (!is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Semantic confidence must be between 0 and 1.');
        }

        if (!is_string($row['reason_code'])) {
            throw new \InvalidArgumentException('Semantic reason_code must be a string.');
        }

        $reasonCode = trim($row['reason_code']);
        if (
            $reasonCode === ''
             || strlen($reasonCode) > 64
             || preg_match('/^[a-z0-9][a-z0-9_]*$/D', $reasonCode) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Semantic reason_code must be lower snake_case and at most 64 characters.'
            );
        }

        $thresholdApplied = $confidence < $this->confidenceThreshold;
        $effectiveClass = $thresholdApplied ? SemanticTicketClass::UNCERTAIN : $predictedClass;

        return new SemanticTicketClassification(
            $ticketId,
            $effectiveClass,
            $predictedClass,
            $confidence,
            $reasonCode,
            $thresholdApplied,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function ticketId(array $row): int
    {
        $value = $row['ticket_id'] ?? null;

        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException('Semantic ticket_id must be an integer.');
        }

        $ticketId = (int) $value;
        if ($ticketId <= 0) {
            throw new \InvalidArgumentException('Semantic ticket_id must be positive.');
        }

        return $ticketId;
    }
}
