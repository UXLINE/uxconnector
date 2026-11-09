<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\DTO;

final readonly class BatchProcessResult
{
    public function __construct(
        public int $processed,
        public int $failed,
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $warnings = 0
    ) {}
}
