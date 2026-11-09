<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\PostProcessing;

/** Safe, structured warning metadata. Implementations must not expose tokens or signed URLs. */
interface PostProcessingDiagnosticInterface
{
    public function lastErrorCode(): string;
    public function lastErrorMessage(): string;
}
