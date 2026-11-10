<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\JobSchedule;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\LatestSyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;

final class JobScheduleEvaluator
{
    public static function isDue(
        SyncJob $job,
        SyncExecutionRepositoryInterface $executions,
        ?DateTimeImmutable $now = null
    ): bool {
        $id = $job->id();
        if ($id === null) {
            return false;
        }
        if ($executions instanceof LatestSyncExecutionRepositoryInterface) {
            $latest = $executions->findLatestBySyncJobId($id);
        } else {
            $history = $executions->findBySyncJobId($id);
            usort($history, static fn($a, $b): int => $b->startedAt() <=> $a->startedAt());
            $latest = $history[0] ?? null;
        }
        return JobSchedule::fromJob($job)->isDue($latest, $now ?? new DateTimeImmutable());
    }
}
