<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Enum\SemanticTicketClass;

/**
 * One engine-calculated semantic classification.
 *
 * semanticClass is the effective class after the configured confidence
 * threshold has been applied. predictedClass preserves the class actually
 * returned by the upstream model for provenance.
 */
final readonly class SemanticTicketClassification
{
    public function __construct(
        public int $ticketId,
        public SemanticTicketClass $semanticClass,
        public SemanticTicketClass $predictedClass,
        public float $confidence,
        public string $reasonCode,
        public bool $thresholdApplied
    ) {
        if ($this->ticketId <= 0) {
            throw new \InvalidArgumentException('Semantic classification ticket id must be positive.');
        }

        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new \InvalidArgumentException('Semantic classification confidence must be between 0 and 1.');
        }

        if (
            $this->reasonCode === ''
             || strlen($this->reasonCode) > 64
             || preg_match('/^[a-z0-9][a-z0-9_]*$/D', $this->reasonCode) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Semantic classification reason_code must be lower snake_case and at most 64 characters.',
            );
        }
    }

    /** @return array<string, bool|float|int|string> */
    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'class' => $this->semanticClass->value,
            'predicted_class' => $this->predictedClass->value,
            'confidence' => $this->confidence,
            'reason_code' => $this->reasonCode,
            'threshold_applied' => $this->thresholdApplied,
        ];
    }
}
