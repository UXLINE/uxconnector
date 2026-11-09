<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\ReadModel;

interface LicenseAssignmentReaderInterface
{
    /** @return list<array<string,mixed>> */
    public function recent(int $limit): array;
}
