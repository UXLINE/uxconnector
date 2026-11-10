<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Policy;

use DateTimeImmutable;
use DateTimeZone;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;

/** Per-job cadence. Delay is measured from completion, including manual runs. */
final readonly class JobSchedule
{
    public function __construct(
        public bool $enabled = true,
        public int $intervalMinutes = 60,
        public ?DateTimeImmutable $notBefore = null,
        public bool $retryAfterFailure = false
    ) {
        if ($intervalMinutes < 5 || $intervalMinutes > 43200) {
            throw new \InvalidArgumentException('Job interval must be between 5 and 43200 minutes.');
        }
    }

    public static function fromJob(SyncJob $job): self
    {
        $config = $job->paginationConfig();
        $enabled = $config->option('schedule_enabled') ?? true;
        $interval = $config->option('schedule_interval_minutes') ?? 60;
        $retry = $config->option('schedule_retry_after_failure') ?? false;
        if (!is_bool($enabled) || !is_int($interval) || !is_bool($retry)) {
            throw new \InvalidArgumentException('Job scheduling options have invalid types.');
        }
        $start = $config->option('schedule_start_at');
        $date = null;
        if ($start !== null && $start !== '') {
            if (!is_string($start)) {
                throw new \InvalidArgumentException('Job start date must be an ISO UTC date.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $start, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $start) {
                throw new \InvalidArgumentException('Job start date must be a valid ISO UTC date.');
            }
        }
        return new self($enabled, $interval, $date, $retry);
    }

    public function isDue(?SyncExecution $latest, DateTimeImmutable $now): bool
    {
        if (!$this->enabled || ($this->notBefore !== null && $now < $this->notBefore)) {
            return false;
        }
        if ($latest === null) {
            return true;
        }
        if (in_array($latest->status(), [SyncExecutionStatus::PENDING, SyncExecutionStatus::RUNNING], true)) {
            return false;
        }
        if (
            !$this->retryAfterFailure && in_array($latest->status(), [SyncExecutionStatus::FAILED,
                    SyncExecutionStatus::INTERRUPTED], true)
        ) {
            return false;
        }
        $last = $latest->finishedAt() ?? $latest->startedAt();
        return $now >= $last->modify('+' . $this->intervalMinutes . ' minutes');
    }
}
