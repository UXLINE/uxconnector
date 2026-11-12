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

namespace GlpiPlugin\Uxconnector\Application\Sync\DTO;

/**
 * Outcome of one RunActiveSyncJobsService pass - everything
 * SyncJobCronTask needs to log and report to GLPI, without touching
 * a single Domain or Repository type itself.
 */
final readonly class RunActiveSyncJobsResult
{
    /**
     * @param array<int, string> $errors SyncJob id => error message,
     *        for jobs that raised an exception the Service itself
     *        could not turn into a FAILED SyncExecution (e.g. the
     *        SyncExecution repository itself being unavailable).
     */
    public function __construct(
        public int $totalJobs,
        public int $succeeded,
        public int $failed,
        public array $errors = []
    ) {
    }

    public function hasRun(): bool
    {
        return $this->totalJobs > 0;
    }

    public function isFullySuccessful(): bool
    {
        return $this->totalJobs > 0 && $this->failed === 0;
    }
}
