<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\ReadModel;

use GlpiPlugin\Uxconnector\Domain\Sync\ReadModel\LicenseAssignmentReaderInterface;

final class ListLicenseAssignments
{
    public function __construct(private readonly LicenseAssignmentReaderInterface $reader)
    {
    }

    /** @return list<array<string,mixed>> */
    public function execute(): array
    {
        return $this->reader->recent(100);
    }
}
