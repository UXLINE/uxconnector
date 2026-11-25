<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Sync\Migration\LegacyJobScopeReport;

/** Warn without changing jobs whose historical owner permissions cannot be reconstructed. */
return static function (Migration $migration): void {
    global $DB;

    $report = (new LegacyJobScopeReport($DB))->build();
    $reviewIds = [];
    foreach ($report['active_jobs'] as $job) {
        if ($job['scope_review_required']) {
            $reviewIds[] = $job['job_id'];
        }
    }
    if ($report['active_jobs'] !== []) {
        $migration->addWarningMessage(sprintf(
            'UXConnector: %d active interaction(s). Historical creator permissions were not stored; an administrator must review their intended entity scope before restarting workers.',
            count($report['active_jobs']),
        ));
    }
    if ($reviewIds !== []) {
        $migration->addWarningMessage(sprintf(
            'UXConnector: %d active interaction(s) have an unconfigured, invalid or multiple-entity scope. First IDs: %s. Run bin/console plugins:uxconnector:review-active-job-scopes for the complete read-only inventory.',
            count($reviewIds),
            implode(', ', array_slice($reviewIds, 0, 100)),
        ));
    }
};
