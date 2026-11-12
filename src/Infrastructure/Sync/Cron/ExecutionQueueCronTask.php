<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron;

use CronTask;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;

/** Fast worker for manual executions and targeted failed-item replays. */
final class ExecutionQueueCronTask
{
    /** @return array<string,string> */
    public static function cronInfo(string $name): array
    {
        return ['description' => \__(
            'Processes queued UXConnector executions and failed-item retries.',
            'uxconnector'
        )];
    }

    public static function cronExecutionQueue(CronTask $task): int
    {
        $result = SyncServiceProvider::runQueuedExecutionsService()->execute();
        $task->setVolume($result->totalJobs);
        $task->addVolume(0);
        $task->log(sprintf(
            '%d queued execution(s): %d succeeded, %d failed.',
            $result->totalJobs,
            $result->succeeded,
            $result->failed
        ));
        foreach ($result->errors as $syncJobId => $message) {
            $task->log(sprintf('Queue item #%d: %s', $syncJobId, $message));
        }
        return $result->hasRun() ? 1 : 0;
    }
}
