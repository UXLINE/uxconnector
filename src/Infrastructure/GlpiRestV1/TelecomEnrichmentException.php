<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

/** Stable non-sensitive reasons for the INDIGO telecom enrichment audit trail. */
final class TelecomEnrichmentException extends \RuntimeException
{
    public function __construct(private readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
