<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Provider;

use GlpiPlugin\Uxconnector\Domain\Copilot\Analytics\ItsmAnalyticsProviderInterface;

/**
 * Read-only analytics over the GLPI instance hosting UXConnector.
 *
 * 2.10.2 analytics-semantics rules:
 * - never turn missing samples into a fake "0 s" average;
 * - expose sample sizes/coverage so an LLM can qualify its conclusions;
 * - fall back to ticket dates when imported historical *_delay_stat values are 0
 *   but the corresponding timestamps prove that the duration was not zero;
 * - distinguish opened events from resolved events in time series;
 * - make multi-group / multi-assignee semantics explicit;
 * - enrich SLA breaches with business context and breach duration.
 */
final class LocalGlpiAnalyticsProvider implements ItsmAnalyticsProviderInterface
{
    private const MAX_ROWS = 20000;

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    public function ticketSummary(array $criteria): array
    {
        $rows = $this->filteredTickets($criteria);
        $opened = count($rows);
        $resolved = 0;
        $closed = 0;
        $pending = 0;
        $incidents = 0;
        $requests = 0;
        $resolutionSeconds = [];
        $ownershipSeconds = [];

        foreach ($rows as $row) {
            $status = (int) ($row['status'] ?? 0);
            $type = (int) ($row['type'] ?? 0);
            $resolved += in_array($status, [5, 6], true) ? 1 : 0;
            $closed += $status === 6 ? 1 : 0;
            $pending += $status === 4 ? 1 : 0;
            $incidents += $type === 1 ? 1 : 0;
            $requests += $type === 2 ? 1 : 0;

            // Resolution duration is meaningful only for tickets currently solved/closed.
            // A reopened/processing ticket may still carry an historical solvedate and must not
            // contaminate the current TTR sample.
            if (in_array($status, [5, 6], true)) {
                $solve = $this->durationSeconds($row, 'solve_delay_stat', 'date', 'solvedate');
                if ($solve !== null) {
                    $resolutionSeconds[] = $solve;
                }
            }

            $own = $this->ownershipDurationSeconds($row);
            if ($own !== null) {
                $ownershipSeconds[] = $own;
            }
        }

        $avgOwn = $this->average($ownershipSeconds);
        $avgResolve = $this->average($resolutionSeconds);

        return [
            'period' => $this->period($criteria),
            'total' => $opened,
            'incidents' => $incidents,
            'requests' => $requests,
            'resolved_or_closed' => $resolved,
            'closed' => $closed,
            'pending' => $pending,
            'active_backlog' => max(0, $opened - $resolved),
            'average_time_to_own_seconds' => $avgOwn,
            'average_time_to_own_human' => $this->humanDuration($avgOwn),
            'time_to_own_sample_size' => count($ownershipSeconds),
            'time_to_own_coverage_percent' => $this->coverage(count($ownershipSeconds), $opened),
            'average_time_to_resolve_seconds' => $avgResolve,
            'average_time_to_resolve_human' => $this->humanDuration($avgResolve),
            'time_to_resolve_sample_size' => count($resolutionSeconds),
            'time_to_resolve_population_size' => $resolved,
            'time_to_resolve_coverage_percent' => $this->coverage(count($resolutionSeconds), $resolved),
            'resolution_rate_percent' => $this->coverage($resolved, $opened),
            'period_observation' => $this->periodObservation($criteria),
            'metric_definitions' => [
                'active_backlog' => 'Tickets opened in the requested period that are currently neither solved nor '
                    . 'closed. This is not a historical end-of-period backlog snapshot.',
                'average_time_to_own' => 'Average GLPI take-into-account duration for tickets where that duration '
                    . 'is evaluable.',
                'average_time_to_resolve' => 'Average GLPI solve duration for currently solved/closed tickets '
                    . 'where that duration is evaluable.',
                'time_to_resolve_coverage_percent' => 'Share of currently solved/closed tickets in the period '
                    . 'that have an evaluable resolution duration. This is a data-quality coverage rate, not the '
                    . 'resolution rate.',
                'resolution_rate_percent' => 'Share of tickets opened in the requested period that are currently '
                    . 'solved or closed.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    public function ticketTrends(array $criteria): array
    {
        $bucket = strtolower((string) ($criteria['bucket'] ?? 'day'));
        if (!in_array($bucket, ['day', 'week', 'month'], true)) {
            $bucket = 'day';
        }

        /** @var array<string,array{period:string,opened:int,resolved:int,incidents:int,requests:int}> $series */
        $series = $this->emptySeries($criteria, $bucket);
        $openedTotal = 0;
        $resolvedTotal = 0;

        foreach ($this->allTickets() as $row) {
            $createdDate = (string) ($row['date'] ?? $row['date_creation'] ?? '');
            if ($this->dateInCriteria($createdDate, $criteria)) {
                $key = $this->bucketKey($createdDate, $bucket);
                if ($key !== '') {
                    $series[$key] ??= $this->newTrendBucket($key);
                    $series[$key]['opened']++;
                    $openedTotal++;
                    if ((int) ($row['type'] ?? 0) === 1) {
                        $series[$key]['incidents']++;
                    }
                    if ((int) ($row['type'] ?? 0) === 2) {
                        $series[$key]['requests']++;
                    }
                }
            }

            $resolvedDate = (string) ($row['solvedate'] ?? $row['closedate'] ?? '');
            if ($this->dateInCriteria($resolvedDate, $criteria)) {
                $key = $this->bucketKey($resolvedDate, $bucket);
                if ($key !== '') {
                    $series[$key] ??= $this->newTrendBucket($key);
                    $series[$key]['resolved']++;
                    $resolvedTotal++;
                }
            }
        }

        ksort($series);

        /** @var list<array{period:string,opened:int,resolved:int,incidents:int,requests:int}> $seriesList */
        $seriesList = [];

        foreach ($series as $seriesRow) {
            /** @var array{period:string,opened:int,resolved:int,incidents:int,requests:int} $seriesRow */
            $seriesList[] = $seriesRow;
        }

        return [
            'period' => $this->period($criteria),
            'bucket' => $bucket,
            'opened_total' => $openedTotal,
            'resolved_total' => $resolvedTotal,
            'series' => $seriesList,
            'metric_definitions' => [
                'opened' => 'Tickets whose creation date falls inside the bucket.',
                'resolved' => 'Tickets whose GLPI solve/close date falls inside the bucket, including tickets '
                    . 'created before the requested period.',
                'incidents' => 'Incident tickets opened inside the bucket.',
                'requests' => 'Request tickets opened inside the bucket.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    public function categoryAnalytics(array $criteria): array
    {
        $stats = [];
        foreach ($this->filteredTickets($criteria) as $row) {
            $id = (int) ($row['itilcategories_id'] ?? 0);
            $category = $this->categoryMetadata($id);

            $stats[$id] ??= [
                'category_id' => $category['category_id'],
                'category' => $category['category_name'],
                'category_name' => $category['category_name'],
                'category_path' => $category['category_path'],
                'parent_category_id' => $category['parent_category_id'],
                'parent_category_name' => $category['parent_category_name'],
                'tickets' => 0,
                'incidents' => 0,
                'requests' => 0,
                'resolved_or_closed' => 0,
                'active_backlog' => 0,
                'resolution_seconds_total' => 0,
                'resolution_count' => 0,
            ];
            $stats[$id]['tickets']++;
            if ((int) ($row['type'] ?? 0) === 1) {
                $stats[$id]['incidents']++;
            }
            if ((int) ($row['type'] ?? 0) === 2) {
                $stats[$id]['requests']++;
            }
            if (in_array((int) ($row['status'] ?? 0), [5, 6], true)) {
                $stats[$id]['resolved_or_closed']++;
            } else {
                $stats[$id]['active_backlog']++;
            }
            $seconds = $this->durationSeconds($row, 'solve_delay_stat', 'date', 'solvedate');
            if ($seconds !== null) {
                $stats[$id]['resolution_seconds_total'] += $seconds;
                $stats[$id]['resolution_count']++;
            }
        }

        foreach ($stats as &$row) {
            $sampleSize = (int) $row['resolution_count'];
            $avg = $sampleSize > 0 ? (int) round($row['resolution_seconds_total'] / $sampleSize) : null;
            $row['resolution_sample_size'] = $sampleSize;
            $row['average_resolution_seconds'] = $avg;
            $row['average_resolution_human'] = $this->humanDuration($avg);
            unset($row['resolution_seconds_total'], $row['resolution_count']);
        }
        unset($row);

        usort($stats, static fn(array $a, array $b): int => $b['tickets'] <=> $a['tickets']);
        $limit = max(1, min(50, (int) ($criteria['limit'] ?? 15)));
        return [
            'period' => $this->period($criteria),
            'categories' => array_slice($stats, 0, $limit),
            'note' => 'A null average means no evaluable resolution-duration sample; it is different from a real '
                . '0-second resolution.',
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    public function slaAnalytics(array $criteria): array
    {
        $rows = $this->filteredTickets($criteria);
        $tto = $this->newSlaMetric();
        $ttr = $this->newSlaMetric();
        $breaches = [];
        $now = time();

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $created = $this->timestamp($row['date'] ?? $row['date_creation'] ?? null);
            $assignedAt = $this->timestamp($row['takeintoaccountdate'] ?? null);
            if (
                $assignedAt === null && $created !== null
                && isset($row['takeintoaccount_delay_stat']) && is_numeric($row['takeintoaccount_delay_stat'])
            ) {
                $delay = (int) $row['takeintoaccount_delay_stat'];
                if ($delay >= 0) {
                    $assignedAt = $created + $delay;
                }
            }
            $resolvedAt = $this->timestamp($row['solvedate'] ?? $row['closedate'] ?? null);
            $ttoDeadline = $this->timestamp($row['time_to_own'] ?? null);
            $ttrDeadline = $this->timestamp($row['time_to_resolve'] ?? null);

            $breaches = $this->evaluateSlaMetric($tto, $breaches, $row, 'TTO', $ttoDeadline, $assignedAt, $now);
            $breaches = $this->evaluateSlaMetric($ttr, $breaches, $row, 'TTR', $ttrDeadline, $resolvedAt, $now);
        }

        foreach ([$tto, $ttr] as $unused) {
            unset($unused);
        }
        $tto = $this->finishSlaMetric($tto, count($rows));
        $ttr = $this->finishSlaMetric($ttr, count($rows));

        usort($breaches, static function (array $a, array $b): int {
            return ($b['breach_duration_seconds'] ?? 0) <=> ($a['breach_duration_seconds'] ?? 0);
        });

        return [
            'period' => $this->period($criteria),
            'tickets_scanned' => count($rows),
            'tto' => $tto,
            'ttr' => $ttr,
            'breaches' => array_slice($breaches, 0, max(1, min(100, (int) ($criteria['limit'] ?? 25)))),
            'note' => 'Compliance rate is calculated only on tickets with a parseable GLPI SLA deadline and a '
                . 'completed actual TTO/TTR. Open tickets with a deadline are reported separately as '
                . 'currently_breached/currently_within_deadline.',
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    public function groupAnalytics(array $criteria): array
    {
        global $DB;
        $tickets = $this->filteredTickets($criteria);
        $ticketRows = [];
        foreach ($tickets as $row) {
            $ticketRows[(int) $row['id']] = $row;
        }
        if ($ticketRows === [] || !$DB->tableExists('glpi_groups_tickets')) {
            return [
                'period' => $this->period($criteria),
                'unique_tickets_with_group' => 0,
                'tickets_without_group' => count($ticketRows),
                'multi_group_tickets' => 0,
                'groups' => [],
            ];
        }

        $linksByTicket = [];
        foreach ($DB->request(['FROM' => 'glpi_groups_tickets', 'WHERE' => ['type' => 2]]) as $link) {
            $ticketId = (int) ($link['tickets_id'] ?? 0);
            $groupId = (int) ($link['groups_id'] ?? 0);
            if (!isset($ticketRows[$ticketId]) || $groupId <= 0) {
                continue;
            }
            $linksByTicket[$ticketId][$groupId] = true;
        }

        $stats = [];
        $withGroup = 0;
        $withoutGroup = 0;
        $multiGroup = 0;
        foreach ($ticketRows as $ticketId => $ticketRow) {
            $groupIds = array_map('intval', array_keys($linksByTicket[$ticketId] ?? []));
            if ($groupIds === []) {
                $withoutGroup++;
                continue;
            }
            $withGroup++;
            if (count($groupIds) > 1) {
                $multiGroup++;
            }

            foreach ($groupIds as $groupId) {
                $stats[$groupId] ??= [
                    'group_id' => $groupId,
                    'group' => $this->lookupName('glpi_groups', $groupId) ?? ('Group #' . $groupId),
                    'tickets' => 0,
                    'active_backlog' => 0,
                    'resolved_or_closed' => 0,
                    'resolution_seconds_total' => 0,
                    'resolution_count' => 0,
                    'resolution_from_glpi_stat' => 0,
                    'resolution_from_calendar_fallback' => 0,
                ];
                $stats[$groupId]['tickets']++;
                $status = (int) ($ticketRow['status'] ?? 0);
                if (in_array($status, [5, 6], true)) {
                    $stats[$groupId]['resolved_or_closed']++;
                } else {
                    $stats[$groupId]['active_backlog']++;
                }
                $duration = $this->durationWithSource(
                    $ticketRow,
                    'solve_delay_stat',
                    'date',
                    'solvedate',
                );
                $seconds = $duration['seconds'];

                if ($seconds !== null) {
                    $stats[$groupId]['resolution_seconds_total'] += $seconds;
                    $stats[$groupId]['resolution_count']++;

                    if ($duration['source'] === 'glpi_stat') {
                        $stats[$groupId]['resolution_from_glpi_stat']++;
                    } elseif ($duration['source'] === 'calendar_fallback') {
                        $stats[$groupId]['resolution_from_calendar_fallback']++;
                    }
                }
            }
        }

        foreach ($stats as &$row) {
            $sampleSize = (int) $row['resolution_count'];
            $avg = $sampleSize > 0 ? (int) round($row['resolution_seconds_total'] / $sampleSize) : null;
            $row['resolution_sample_size'] = $sampleSize;
            $row['average_resolution_seconds'] = $avg;
            $row['average_resolution_human'] = $this->humanDuration($avg);
            $row['resolution_fallback_ratio'] = $sampleSize > 0
                ? round($row['resolution_from_calendar_fallback'] / $sampleSize, 4)
                : null;

            unset($row['resolution_seconds_total'], $row['resolution_count']);
        }
        unset($row);

        usort(
            $stats,
            static fn(array $a, array $b): int =>
                ($b['active_backlog'] <=> $a['active_backlog']) ?: ($b['tickets'] <=> $a['tickets'])
        );
        return [
            'period' => $this->period($criteria),
            'unique_tickets_with_group' => $withGroup,
            'tickets_without_group' => $withoutGroup,
            'multi_group_tickets' => $multiGroup,
            'groups' => $stats,
            'note' => 'Group rows are not additive when a ticket is assigned to multiple groups. Top-level '
                . 'unique/multi-group counters describe that overlap.',
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    public function technicianAnalytics(array $criteria): array
    {
        global $DB;
        $tickets = $this->filteredTickets($criteria);
        $ticketRows = [];
        foreach ($tickets as $row) {
            $ticketRows[(int) $row['id']] = $row;
        }

        $excludedUserIds = array_flip($this->intList($criteria['exclude_user_ids'] ?? []));
        $stats = [];
        $assignmentsByTicket = [];

        if ($ticketRows !== [] && $DB->tableExists('glpi_tickets_users')) {
            foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['type' => 2]]) as $link) {
                $ticketId = (int) ($link['tickets_id'] ?? 0);
                $userId = (int) ($link['users_id'] ?? 0);
                if (!isset($ticketRows[$ticketId]) || $userId <= 0 || isset($excludedUserIds[$userId])) {
                    continue;
                }
                $assignmentsByTicket[$ticketId][$userId] = true;
            }
        }

        foreach ($assignmentsByTicket as $ticketId => $userIds) {
            foreach (array_keys($userIds) as $userIdRaw) {
                $userId = (int) $userIdRaw;
                $stats[$userId] ??= $this->newTechnicianStat($userId);
                $stats[$userId]['assigned_ticket_ids'][$ticketId] = true;
                $status = (int) ($ticketRows[$ticketId]['status'] ?? 0);
                if (in_array($status, [5, 6], true)) {
                    $stats[$userId]['resolved_or_closed_ticket_ids'][$ticketId] = true;
                } else {
                    $stats[$userId]['active_backlog_ticket_ids'][$ticketId] = true;
                }
            }
        }

        if ($ticketRows !== [] && $DB->tableExists('glpi_tickettasks')) {
            foreach ($DB->request(['FROM' => 'glpi_tickettasks']) as $task) {
                $ticketId = (int) ($task['tickets_id'] ?? 0);
                if (!isset($ticketRows[$ticketId])) {
                    continue;
                }
                $technicalUserId = (int) ($task['users_id_tech'] ?? 0);
                $userId = $technicalUserId;
                if ($userId <= 0 || isset($excludedUserIds[$userId])) {
                    continue;
                }
                $stats[$userId] ??= $this->newTechnicianStat($userId);
                $stats[$userId]['intervened_ticket_ids'][$ticketId] = true;
                $stats[$userId]['tasks']++;
                $stats[$userId]['task_work_seconds'] += max(0, (int) ($task['actiontime'] ?? 0));
            }
        }

        $uniqueAssignedTickets = [];
        $ticketsWithoutAssignee = 0;
        $multiAssigneeTickets = 0;
        foreach ($ticketRows as $ticketId => $_row) {
            $assignees = array_keys($assignmentsByTicket[$ticketId] ?? []);
            if ($assignees === []) {
                $ticketsWithoutAssignee++;
            } else {
                $uniqueAssignedTickets[$ticketId] = true;
                if (count($assignees) > 1) {
                    $multiAssigneeTickets++;
                }
            }
        }

        foreach ($stats as &$row) {
            $row['assigned_tickets'] = count($row['assigned_ticket_ids']);
            $row['intervened_tickets'] = count($row['intervened_ticket_ids']);
            $row['active_backlog'] = count($row['active_backlog_ticket_ids']);
            $row['resolved_or_closed'] = count($row['resolved_or_closed_ticket_ids']);
            $row['task_work_human'] = $this->humanDuration($row['task_work_seconds']);
            $avgTask = $row['tasks'] > 0 ? (int) round($row['task_work_seconds'] / $row['tasks']) : null;
            $row['average_task_duration_seconds'] = $avgTask;
            $row['average_task_duration_human'] = $this->humanDuration($avgTask);
            unset(
                $row['assigned_ticket_ids'],
                $row['intervened_ticket_ids'],
                $row['active_backlog_ticket_ids'],
                $row['resolved_or_closed_ticket_ids']
            );
        }
        unset($row);

        usort(
            $stats,
            static fn(array $a, array $b): int =>
                ($b['active_backlog'] <=> $a['active_backlog']) ?: ($b['task_work_seconds'] <=> $a['task_work_seconds'])
        );
        return [
            'period' => $this->period($criteria),
            'unique_tickets_with_assignee' => count($uniqueAssignedTickets),
            'tickets_without_assignee' => $ticketsWithoutAssignee,
            'multi_assignee_tickets' => $multiAssigneeTickets,
            'excluded_user_ids' => array_values(array_map('intval', array_keys($excludedUserIds))),
            'technicians' => $stats,
            'note' => 'assigned_tickets counts ticket assignment; intervened_tickets counts distinct tickets on '
                . 'which the technician performed a task. No user is excluded automatically.',
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    public function comparePeriods(array $criteria): array
    {
        $a = is_array($criteria['period_a'] ?? null) ? $criteria['period_a'] : [];
        $b = is_array($criteria['period_b'] ?? null) ? $criteria['period_b'] : [];
        $summaryA = $this->ticketSummary($a);
        $summaryB = $this->ticketSummary($b);
        $metrics = [
            'total',
            'incidents',
            'requests',
            'resolved_or_closed',
            'active_backlog',
            'average_time_to_own_seconds',
            'average_time_to_resolve_seconds'
        ];
        $delta = [];
        foreach ($metrics as $metric) {
            $left = $summaryA[$metric] ?? null;
            $right = $summaryB[$metric] ?? null;
            if (!is_numeric($left) || !is_numeric($right)) {
                $delta[$metric] = ['absolute' => null, 'percent' => null];
                continue;
            }
            $absolute = $right - $left;
            $delta[$metric] = [
                'absolute' => $absolute,
                'percent' => (float) $left !== 0.0 ? round(($absolute / $left) * 100, 2) : null,
            ];
        }

        $warnings = [];
        foreach (
            [
            'time_to_own' => 'time_to_own_sample_size',
            'time_to_resolve' => 'time_to_resolve_sample_size',
            ] as $label => $sampleField
        ) {
            $sampleA = (int) ($summaryA[$sampleField] ?? 0);
            $sampleB = (int) ($summaryB[$sampleField] ?? 0);
            if ($sampleA === 0 || $sampleB === 0) {
                $warnings[] = sprintf(
                    '%s comparison is not reliable because one period has no evaluable sample.',
                    $label
                );
            } elseif ($sampleA < 5 || $sampleB < 5) {
                $warnings[] = sprintf(
                    '%s comparison is based on a small sample in at least one period (%d vs %d).',
                    $label,
                    $sampleA,
                    $sampleB
                );
            }
        }

        $observationA = is_array($summaryA['period_observation'] ?? null) ? $summaryA['period_observation'] : [];
        $observationB = is_array($summaryB['period_observation'] ?? null) ? $summaryB['period_observation'] : [];
        if (($observationA['is_partial'] ?? false) || ($observationB['is_partial'] ?? false)) {
            $warnings[] = 'At least one compared period is still in progress or extends beyond the current date. '
                . 'Raw totals are not directly comparable to a completed period; prefer normalized daily rates or '
                . 'compare equally elapsed windows.';
        }

        $dailyRates = [
            'period_a' => $this->dailyRates($summaryA),
            'period_b' => $this->dailyRates($summaryB),
        ];

        return [
            'period_a' => $summaryA,
            'period_b' => $summaryB,
            'delta_b_vs_a' => $delta,
            'warnings' => array_values(array_unique($warnings)),
            'normalized_daily_rates' => $dailyRates,
            'note' => 'active_backlog compares the current status of tickets opened in each period; it is not a '
                . 'historical backlog snapshot at each period end. When a period is partial, use '
                . 'normalized_daily_rates or compare equally elapsed windows instead of raw monthly totals.',
        ];
    }


    /**
     * Load current assigned technical groups for a ticket batch.
     *
     * This is intentionally batch-oriented: no SQL query is executed per ticket.
     *
     * @param list<int> $ticketIds
     *
     * @return array<int,list<array{id:int,name:string}>>
     */
    private function currentTechnicalGroupsByTicket(array $ticketIds): array
    {
        global $DB;

        $ticketIds = array_values(array_unique(array_filter(
            array_map('intval', $ticketIds),
            static fn(int $id): bool => $id > 0,
        )));

        if ($ticketIds === []) {
            return [];
        }

        $result = [];
        foreach ($ticketIds as $ticketId) {
            $result[$ticketId] = [];
        }

        if (
            !$DB->tableExists('glpi_groups_tickets')
             || !$DB->tableExists('glpi_groups')
        ) {
            return $result;
        }

        $groupIdsByTicket = [];
        $wantedGroupIds = [];

        foreach (
            $DB->request([
            'SELECT' => ['tickets_id', 'groups_id'],
            'FROM' => 'glpi_groups_tickets',
            'WHERE' => [
                'type' => 2,
                'tickets_id' => $ticketIds,
            ],
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $ticketId = (int) ($row['tickets_id'] ?? 0);
            $groupId = (int) ($row['groups_id'] ?? 0);

            if (
                $ticketId <= 0
                 || $groupId <= 0
                 || !array_key_exists($ticketId, $result)
            ) {
                continue;
            }

            $groupIdsByTicket[$ticketId][$groupId] = true;
            $wantedGroupIds[$groupId] = true;
        }

        if ($wantedGroupIds === []) {
            return $result;
        }

        $groupNames = [];

        foreach (
            $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM' => 'glpi_groups',
            'WHERE' => [
                'id' => array_map('intval', array_keys($wantedGroupIds)),
            ],
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $groupId = (int) ($row['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $groupNames[$groupId] = $name !== ''
                ? $name
                : 'Group #' . $groupId;
        }

        foreach ($groupIdsByTicket as $ticketId => $groupIds) {
            $ids = array_map('intval', array_keys($groupIds));
            sort($ids, SORT_NUMERIC);

            foreach ($ids as $groupId) {
                $result[$ticketId][] = [
                    'id' => $groupId,
                    'name' => $groupNames[$groupId] ?? ('Group #' . $groupId),
                ];
            }
        }

        return $result;
    }

    /**
     * Load technical-group history and solvedate history for a ticket batch.
     *
     * Two SQL queries are used for the whole batch:
     * - assigned technical group relation events;
     * - solvedate history events.
     *
     * @param list<int> $ticketIds
     *
     * @return array{
     *   group_logs:array<int,list<array<string,mixed>>>,
     *   solvedate_logs:array<int,list<array<string,mixed>>>
     * }
     */
    private function technicalGroupHistoryDataset(array $ticketIds): array
    {
        global $DB;

        $ticketIds = array_values(array_unique(array_filter(
            array_map('intval', $ticketIds),
            static fn(int $id): bool => $id > 0,
        )));

        $result = [
            'group_logs' => [],
            'solvedate_logs' => [],
        ];

        foreach ($ticketIds as $ticketId) {
            $result['group_logs'][$ticketId] = [];
            $result['solvedate_logs'][$ticketId] = [];
        }

        if ($ticketIds === [] || !$DB->tableExists('glpi_logs')) {
            return $result;
        }

        foreach (
            $DB->request([
            'SELECT' => [
                'id',
                'items_id',
                'date_mod',
                'linked_action',
                'old_value',
                'new_value',
            ],
            'FROM' => 'glpi_logs',
            'WHERE' => [
                'itemtype' => 'Ticket',
                'items_id' => $ticketIds,
                'itemtype_link' => 'Group',
                'id_search_option' => 8,
                'linked_action' => [15, 16],
            ],
            'ORDER' => [
                'items_id ASC',
                'date_mod ASC',
                'id ASC',
            ],
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $ticketId = (int) ($row['items_id'] ?? 0);
            if (!array_key_exists($ticketId, $result['group_logs'])) {
                continue;
            }

            $result['group_logs'][$ticketId][] = $row;
        }

        foreach (
            $DB->request([
            'SELECT' => [
                'id',
                'items_id',
                'date_mod',
                'old_value',
                'new_value',
            ],
            'FROM' => 'glpi_logs',
            'WHERE' => [
                'itemtype' => 'Ticket',
                'items_id' => $ticketIds,
                'id_search_option' => 17,
            ],
            'ORDER' => [
                'items_id ASC',
                'date_mod ASC',
                'id ASC',
            ],
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $ticketId = (int) ($row['items_id'] ?? 0);
            if (!array_key_exists($ticketId, $result['solvedate_logs'])) {
                continue;
            }

            $result['solvedate_logs'][$ticketId][] = $row;
        }

        return $result;
    }

    /**
     * Resolve Incidentologue initial and resolution technical-group attribution.
     *
     * Historical data is preferred whenever it can provide an unambiguous
     * attribution. Current GLPI group relations are an explicit fallback for
     * datasets imported without usable history.
     *
     * @param list<array<string,mixed>> $tickets
     *
     * @return array<int,array<string,mixed>>
     */
    private function incidentologyGroupAttributions(array $tickets): array
    {
        $ticketsById = [];

        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $ticketId = (int) ($ticket['id'] ?? 0);
            if ($ticketId <= 0) {
                continue;
            }

            $ticketsById[$ticketId] = $ticket;
        }

        if ($ticketsById === []) {
            return [];
        }

        $ticketIds = array_map('intval', array_keys($ticketsById));

        $currentGroups = $this->currentTechnicalGroupsByTicket($ticketIds);
        $historyDataset = $this->technicalGroupHistoryDataset($ticketIds);

        $result = [];

        foreach ($ticketsById as $ticketId => $ticket) {
            $groupLogs = $historyDataset['group_logs'][$ticketId] ?? [];
            $solvedateLogs = $historyDataset['solvedate_logs'][$ticketId] ?? [];
            $ticketCurrentGroups = $currentGroups[$ticketId] ?? [];

            /*
             * Initial assignment is independent from ticket resolution.
             * Take the first parseable historical technical-group ADD.
             */
            $initialHistoricalGroup = null;

            foreach ($groupLogs as $log) {
                if (!is_array($log)) {
                    continue;
                }

                if ((int) ($log['linked_action'] ?? 0) !== 15) {
                    continue;
                }

                $rawGroup = $log['new_value'] ?? null;

                $group = $this->parseHistoricalGroup(
                    is_string($rawGroup) ? $rawGroup : null,
                );

                if ($group === null) {
                    continue;
                }

                $initialHistoricalGroup = $group;
                break;
            }

            $initialGroup = null;
            $initialSource = 'unavailable';

            if ($initialHistoricalGroup !== null) {
                $initialGroup = $initialHistoricalGroup;
                $initialSource = 'historical_log';
            } elseif (count($ticketCurrentGroups) === 1) {
                $initialGroup = $ticketCurrentGroups[0];
                $initialSource = 'current_group_fallback';
            } elseif (count($ticketCurrentGroups) > 1) {
                $initialSource = 'ambiguous';
            }

            /*
             * Resolution attribution only applies to tickets currently in
             * solved/closed status. An unresolved ticket must never be counted
             * as internally resolved merely because it has a current group.
             */
            $status = (int) ($ticket['status'] ?? 0);
            $isResolvedOrClosed = in_array($status, [5, 6], true);

            $resolutionGroup = null;
            $resolutionCandidates = [];
            $resolutionSource = 'not_applicable';
            $replay = null;

            if ($isResolvedOrClosed) {
                $replay = $this->replayTechnicalGroupHistoryFromDataset(
                    $ticket,
                    $groupLogs,
                    $solvedateLogs,
                );

                if ($replay['has_usable_history'] ?? false) {
                    $resolutionCandidates = $replay['active_groups_at_resolution'] ?? [];
                    $candidateCount = count($resolutionCandidates);

                    if ($candidateCount === 1) {
                        $resolutionGroup = $resolutionCandidates[0];
                        $resolutionSource = 'historical_log';
                    } elseif ($candidateCount > 1) {
                        $resolutionSource = 'ambiguous';
                    } else {
                        $resolutionSource = 'unavailable';
                    }
                } else {
                    $resolutionCandidates = $ticketCurrentGroups;
                    $candidateCount = count($ticketCurrentGroups);

                    if ($candidateCount === 1) {
                        $resolutionGroup = $ticketCurrentGroups[0];
                        $resolutionSource = 'current_group_fallback';
                    } elseif ($candidateCount > 1) {
                        $resolutionSource = 'ambiguous';
                    } else {
                        $resolutionSource = 'unavailable';
                    }
                }
            }

            $result[$ticketId] = [
                'ticket_id' => $ticketId,

                'initial_assignment_group' => $initialGroup,
                'initial_assignment_source' => $initialSource,

                'resolution_group' => $resolutionGroup,
                'resolution_group_source' => $resolutionSource,
                'resolution_group_candidates' => array_values($resolutionCandidates),

                'current_groups' => array_values($ticketCurrentGroups),

                'is_resolved_or_closed' => $isResolvedOrClosed,

                'historical_group_data_available' => $groupLogs !== [],

                'history_event_count' => $replay !== null
                    ? (int) ($replay['history_event_count'] ?? 0)
                    : count($groupLogs),

                'unparsed_history_event_count' => $replay !== null
                    ? (int) ($replay['unparsed_history_event_count'] ?? 0)
                    : 0,

                'resolution_cutoff_source' => $replay['cutoff_source'] ?? 'not_applicable',

                'resolution_history_boundary_id' => $replay['resolution_history_boundary_id'] ?? null,
            ];
        }

        return $result;
    }


    /**
     * Resolve GLPI technical support groups for Incidentologue.
     *
     * No GLPI group ID is hardcoded.
     *
     * @param array<string,mixed> $criteria
     *
     * @return array<string,mixed>
     */
    public function listIncidentologyGroups(array $criteria): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_groups')) {
            return [
                'success' => false,
                'error' => 'glpi_groups table is unavailable.',
                'groups' => [],
            ];
        }

        $query = trim((string) ($criteria['query'] ?? ''));

        $limit = max(
            1,
            min(
                100,
                (int) ($criteria['limit'] ?? 50),
            ),
        );

        $normalize = static function (string $value): string {
            if (function_exists('mb_strtolower')) {
                return mb_strtolower($value, 'UTF-8');
            }

            return strtolower($value);
        };

        $needle = $normalize($query);

        $groups = [];
        $exactMatchCount = 0;

        foreach (
            $DB->request([
            'FROM' => 'glpi_groups',
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $groupId = (int) ($row['id'] ?? 0);

            if ($groupId <= 0) {
                continue;
            }

            /*
             * Incidentologue targets technical assignment groups.
             * GLPI marks assignable groups with is_assign.
             */
            if (
                array_key_exists('is_assign', $row)
                && (int) $row['is_assign'] !== 1
            ) {
                continue;
            }

            $name = trim(
                (string) ($row['name'] ?? ''),
            );

            if ($name === '') {
                $name = 'Group #' . $groupId;
            }

            $completeName = trim(
                (string) ($row['completename'] ?? ''),
            );

            if ($completeName === '') {
                $completeName = $name;
            }

            $normalizedName = $normalize($name);
            $normalizedCompleteName =
               $normalize($completeName);

            $exactMatch = (
                $needle !== ''
             && (
                 $normalizedName === $needle
                 || $normalizedCompleteName === $needle
             )
            );

            if (
                $needle !== ''
                && !$exactMatch
                && !str_contains(
                    $normalizedName,
                    $needle,
                )
                && !str_contains(
                    $normalizedCompleteName,
                    $needle,
                )
            ) {
                continue;
            }

            if ($exactMatch) {
                $exactMatchCount++;
            }

            $groups[] = [
                'id' => $groupId,
                'name' => $name,
                'complete_name' => $completeName,
                'entity_id' => (int) (
                    $row['entities_id'] ?? 0
                ),
                'is_recursive' => (bool) (
                    $row['is_recursive'] ?? false
                ),
                'exact_match' => $exactMatch,
            ];
        }

        usort(
            $groups,
            static function (array $a, array $b): int {
                $exactComparison =
                    ((int) ($b['exact_match'] ?? false))
                    <=>
                    ((int) ($a['exact_match'] ?? false));

                if ($exactComparison !== 0) {
                    return $exactComparison;
                }

                $nameComparison = strnatcasecmp(
                    (string) (
                        $a['complete_name']
                        ?? $a['name']
                        ?? ''
                    ),
                    (string) (
                        $b['complete_name']
                        ?? $b['name']
                        ?? ''
                    ),
                );

                if ($nameComparison !== 0) {
                    return $nameComparison;
                }

                return
                    ((int) ($a['id'] ?? 0))
                    <=>
                    ((int) ($b['id'] ?? 0));
            },
        );

        $totalMatches = count($groups);

        $groups = array_slice(
            $groups,
            0,
            $limit,
        );

        return [
            'success' => true,
            'query' => $query !== ''
                ? $query
                : null,
            'count' => count($groups),
            'total_matches' => $totalMatches,
            'exact_match_count' => $exactMatchCount,
            'groups' => $groups,
            'semantics' => [
                'group_type' =>
                    'GLPI assignable technical support group',
                'purpose' =>
                    'Resolve a user-facing support-group name '
                    . 'to a GLPI group ID before Incidentologue analysis.',
                'disambiguation' =>
                    'Use a unique exact match automatically. '
                    . 'If several exact matches exist, ask the user '
                    . 'to disambiguate instead of guessing.',
            ],
        ];
    }
    /**
     * Incidentologue global analysis.
     *
     * Scope semantics:
     * - a ticket belongs to the analysed group when its resolved initial
     *   technical assignment group matches group_id;
     * - historical assignment is preferred;
     * - current technical group is an explicit fallback when history is absent;
     * - ambiguous/unavailable initial attribution is excluded from the group
     *   scope and exposed in data_quality;
     * - "resolved internally" means the resolved resolution group matches the
     *   analysed initial group;
     * - unresolved tickets remain in ticket_count but never increment
     *   resolved_internally.
     *
     * @param array<string,mixed> $criteria
     *
     * @return array<string,mixed>
     */
    public function incidentologyGlobal(array $criteria): array
    {
        $groupId = (int) ($criteria['group_id'] ?? 0);

        if ($groupId <= 0) {
            return [
                'success' => false,
                'error' => 'group_id is required and must be greater than 0.',
                'period' => $this->period($criteria),
            ];
        }

        $groupName = $this->lookupName('glpi_groups', $groupId)
            ?? ('Group #' . $groupId);

        /*
         * group_id is intentionally not consumed by filteredTickets().
         * First obtain the date/entity/type/category candidate population,
         * then apply historical/fallback group attribution.
         */
        $candidateTickets = $this->filteredTickets($criteria);
        $attributions = $this->incidentologyGroupAttributions($candidateTickets);

        $initialSources = [
            'historical_log' => 0,
            'current_group_fallback' => 0,
            'ambiguous' => 0,
            'unavailable' => 0,
        ];

        $scopeTickets = [];

        foreach ($candidateTickets as $ticket) {
            $ticketId = (int) ($ticket['id'] ?? 0);
            if ($ticketId <= 0) {
                continue;
            }

            $attribution = $attributions[$ticketId] ?? null;
            if (!is_array($attribution)) {
                $initialSources['unavailable']++;
                continue;
            }

            $initialSource = (string) (
                $attribution['initial_assignment_source']
                ?? 'unavailable'
            );

            if (!array_key_exists($initialSource, $initialSources)) {
                $initialSources[$initialSource] = 0;
            }

            $initialSources[$initialSource]++;

            $initialGroup = $attribution['initial_assignment_group'] ?? null;

            if (
                is_array($initialGroup)
                 && (int) ($initialGroup['id'] ?? 0) === $groupId
            ) {
                $scopeTickets[] = $ticket;
            }
        }

        $candidateCount = count($candidateTickets);
        $effectiveInitialAttribution =
            (int) ($initialSources['historical_log'] ?? 0)
            + (int) ($initialSources['current_group_fallback'] ?? 0);

        $historicalInitialCount =
            (int) ($initialSources['historical_log'] ?? 0);

        $baseDataQuality = [
            'candidate_tickets' => $candidateCount,
            'initial_assignment_sources' => $initialSources,
            'initial_assignment_effective_coverage_percent' =>
                $candidateCount > 0
                    ? round(($effectiveInitialAttribution / $candidateCount) * 100, 2)
                    : null,
            'initial_assignment_historical_coverage_percent' =>
                $candidateCount > 0
                    ? round(($historicalInitialCount / $candidateCount) * 100, 2)
                    : null,
        ];

        if ($scopeTickets === []) {
            return [
                'success' => false,
                'error' => sprintf(
                    "No tickets found for initial technical group '%s' (%d) in the requested scope.",
                    $groupName,
                    $groupId,
                ),
                'period' => $this->period($criteria),
                'group' => [
                    'id' => $groupId,
                    'name' => $groupName,
                ],
                'ticketCount' => 0,
                'dataQuality' => $baseDataQuality,
            ];
        }

        $scopeTicketIds = array_values(array_map(
            static fn(array $ticket): int => (int) ($ticket['id'] ?? 0),
            $scopeTickets,
        ));

        $igDataset = $this->incidentologyIgManagerDataset($scopeTicketIds);

        $uxKnowDashDataset = $this->incidentologyUxKnowDashDataset(
            $scopeTickets,
            $groupId,
        );

        $ticketCount = count($scopeTickets);
        $resolvedInternally = 0;

        $categoryCounts = [];
        $subCategoryCountsByCategory = [];
        $channelCounts = [];
        $locationCounts = [];

        $ticketsByMonth = [];
        $ticketsByDayOfWeek = [];
        $distinctDatesByDayOfWeek = [];

        $resolutionHoursSum = 0.0;
        $ticketsWithValidDates = 0;
        $invalidCreationDatesMonth = 0;

        $resolutionSources = [
            'historical_log' => 0,
            'current_group_fallback' => 0,
            'ambiguous' => 0,
            'unavailable' => 0,
            'not_applicable' => 0,
        ];

        $resolvedOrClosedInScope = 0;

        /*
         * IGManager enrichment.
         *
         * Legacy Incidentologue visionIG expects:
         * - number of distinct parent incidents;
         * - number of consolidated tickets.
         *
         * We additionally expose IG/IP/IC qualification and parent-type
         * distributions without changing those legacy semantics.
         */
        $igParentIds = [];
        $igConsolidatedTicketCount = 0;
        $igParentTypeCounts = [];
        $igQualificationCounts = [];
        $igQualifiedTicketCount = 0;

        /*
         * UXKnowDash enrichment.
         *
         * Targets remain reference values only. They are never compared
         * directly with Incidentologue resolutionRate because the latter is
         * an internal-resolution/autonomy metric, not UXKnowDash FCR.
         */
        $uxSupportLevelCounts = [];
        $uxSupportLevelMappedTickets = 0;
        $uxTargetMappedTickets = 0;
        $uxInheritedSupportLevelTickets = 0;
        $uxInheritedTargetTickets = 0;
        $uxTicketsWithInvalidTargetOverride = 0;
        $uxCategoryEnrichments = [];

        /*
         * Local lookup caches avoid repeatedly querying the same GLPI
         * request type/location across thousands of tickets.
         */
        $requestTypeNames = [];
        $locationNames = [];

        foreach ($scopeTickets as $ticket) {
            $ticketId = (int) ($ticket['id'] ?? 0);
            $attribution = $attributions[$ticketId] ?? [];

            /*
             * ---------------------
             * IGManager enrichment
             * ---------------------
             */
            if ($igDataset['available'] ?? false) {
                $qualification =
                    $igDataset['qualifications'][$ticketId]
                    ?? null;

                if (is_array($qualification)) {
                    $incidentType = trim((string) (
                        $qualification['incident_type']
                        ?? ''
                    ));

                    if ($incidentType === '') {
                        $incidentType = '(Non renseigné)';
                    }

                    $igQualificationCounts[$incidentType] =
                        ($igQualificationCounts[$incidentType] ?? 0) + 1;

                    $igQualifiedTicketCount++;
                }

                $parentRelation =
                    $igDataset['parent_relations'][$ticketId]
                    ?? null;

                if (is_array($parentRelation)) {
                    $parentId = (int) (
                        $parentRelation['parent_ticket_id']
                        ?? 0
                    );

                    if ($parentId > 0) {
                        $igParentIds[$parentId] = true;
                    }

                    $parentType = trim((string) (
                        $parentRelation['parent_type']
                        ?? ''
                    ));

                    if ($parentType === '') {
                        $parentType = '(Non renseigné)';
                    }

                    $igParentTypeCounts[$parentType] =
                        ($igParentTypeCounts[$parentType] ?? 0) + 1;

                    $igConsolidatedTicketCount++;
                }
            }

            /*
             * ---------------------
             * UXKnowDash enrichment
             * ---------------------
             */
            $uxTicket =
                $uxKnowDashDataset['tickets'][$ticketId]
                ?? null;

            if (is_array($uxTicket)) {
                $supportLevel = trim((string) (
                    $uxTicket['support_level']
                    ?? ''
                ));

                if ($supportLevel !== '') {
                    $uxSupportLevelCounts[$supportLevel] =
                       ($uxSupportLevelCounts[$supportLevel] ?? 0) + 1;

                    $uxSupportLevelMappedTickets++;

                    $supportSourceEntityId =
                       $uxTicket['support_level_source_entity_id']
                         ?? null;

                    $ticketEntityId =
                        (int) ($uxTicket['ticket_entity_id'] ?? 0);

                    if (
                        $supportSourceEntityId !== null
                         && (int) $supportSourceEntityId !== $ticketEntityId
                    ) {
                        $uxInheritedSupportLevelTickets++;
                    }
                }

                if (is_array($uxTicket['targets'] ?? null)) {
                    $uxTargetMappedTickets++;

                    $targetSourceEntityId =
                        $uxTicket['target_source_entity_id']
                        ?? null;

                    $ticketEntityId =
                        (int) ($uxTicket['ticket_entity_id'] ?? 0);

                    if (
                        $targetSourceEntityId !== null
                         && (int) $targetSourceEntityId !== $ticketEntityId
                    ) {
                        $uxInheritedTargetTickets++;
                    }
                }

                if (
                    is_array($uxTicket['invalid_target_overrides'] ?? null)
                      && $uxTicket['invalid_target_overrides'] !== []
                ) {
                    $uxTicketsWithInvalidTargetOverride++;
                }
            }

            /*
             * ---------------------
             * Resolution attribution
             * ---------------------
             */
            $resolutionSource = (string) (
                $attribution['resolution_group_source']
                ?? 'unavailable'
            );

            if (!array_key_exists($resolutionSource, $resolutionSources)) {
                $resolutionSources[$resolutionSource] = 0;
            }

            $resolutionSources[$resolutionSource]++;

            $isResolvedOrClosed = (bool) (
                $attribution['is_resolved_or_closed']
                ?? false
            );

            if ($isResolvedOrClosed) {
                $resolvedOrClosedInScope++;
            }

            $resolutionGroup = $attribution['resolution_group'] ?? null;

            if (
                is_array($resolutionGroup)
                 && (int) ($resolutionGroup['id'] ?? 0) === $groupId
            ) {
                $resolvedInternally++;
            }

            /*
             * ---------------------
             * Category / subcategory
             * ---------------------
             *
             * The former Excel engine used two physical columns.
             * GLPI stores a hierarchy, therefore:
             * - immediate parent = Incidentologue category;
             * - leaf            = Incidentologue subcategory;
             * - root-only       = category with "(Non renseigné)" subcategory.
             */
            $categoryId = (int) ($ticket['itilcategories_id'] ?? 0);
            $categoryMetadata = $this->categoryMetadata($categoryId);

            if ($categoryId <= 0) {
                $categoryLabel = '(Non renseigné)';
                $subCategoryLabel = '(Non renseigné)';
            } else {
                $categoryName = trim((string) (
                    $categoryMetadata['category_name']
                    ?? ('Category #' . $categoryId)
                ));

                $parentCategoryName = trim((string) (
                    $categoryMetadata['parent_category_name']
                    ?? ''
                ));

                if ($parentCategoryName !== '') {
                    $categoryLabel = $parentCategoryName;
                    $subCategoryLabel = $categoryName !== ''
                        ? $categoryName
                        : '(Non renseigné)';
                } else {
                    $categoryLabel = $categoryName !== ''
                        ? $categoryName
                        : '(Non renseigné)';
                    $subCategoryLabel = '(Non renseigné)';
                }
            }

            $categoryCounts[$categoryLabel] =
                ($categoryCounts[$categoryLabel] ?? 0) + 1;

            $subCategoryCountsByCategory[$categoryLabel] ??= [];

            $subCategoryCountsByCategory[$categoryLabel][$subCategoryLabel] =
                ($subCategoryCountsByCategory[$categoryLabel][$subCategoryLabel] ?? 0) + 1;

            /*
             * UXKnowDash aggregation stays attached to the native GLPI leaf
             * category ID, even though Incidentologue presents parent/leaf as
             * category/subcategory.
             */
            $uxCategoryKey = $categoryId;

            $uxCategoryEnrichments[$uxCategoryKey] ??= [
                'category_id' =>
                    $categoryMetadata['category_id'] ?? $categoryId,

                'category_name' =>
                    $categoryMetadata['category_name']
                    ?? (
                        $categoryId > 0
                            ? 'Category #' . $categoryId
                            : '(Non renseigné)'
                    ),

                'category_path' =>
                    $categoryMetadata['category_path']
                    ?? null,

                'tickets' => 0,
                'support_levels' => [],
                'support_level_sources' => [],
                'target_variants' => [],
                'tickets_with_invalid_target_override' => 0,
            ];

            $uxCategoryEnrichments[$uxCategoryKey]['tickets']++;

            if (is_array($uxTicket ?? null)) {
                $supportLevel = trim((string) (
                    $uxTicket['support_level']
                    ?? ''
                ));

                if ($supportLevel !== '') {
                    $uxCategoryEnrichments[$uxCategoryKey]
                        ['support_levels'][$supportLevel] =
                            (
                                $uxCategoryEnrichments[$uxCategoryKey]
                                    ['support_levels'][$supportLevel]
                                ?? 0
                            ) + 1;

                    $supportSourceEntityId =
                        $uxTicket['support_level_source_entity_id']
                        ?? null;

                    $supportSourceEntityName =
                        $uxTicket['support_level_source_entity_name']
                        ?? null;

                    if ($supportSourceEntityId !== null) {
                        $sourceKey = (string) $supportSourceEntityId;

                        $uxCategoryEnrichments[$uxCategoryKey]
                           ['support_level_sources'][$sourceKey] ??= [
                               'entity_id' =>
                                   (int) $supportSourceEntityId,
                               'entity_name' =>
                                   $supportSourceEntityName,
                               'tickets' => 0,
                           ];

                           $uxCategoryEnrichments[$uxCategoryKey]
                           ['support_level_sources'][$sourceKey]
                           ['tickets']++;
                    }
                }

                $targets = $uxTicket['targets'] ?? null;

                if (is_array($targets)) {
                    $targetSourceEntityId =
                        $uxTicket['target_source_entity_id']
                        ?? null;

                    $targetSourceEntityName =
                        $uxTicket['target_source_entity_name']
                        ?? null;

                    $variantKey = implode('|', [
                        (string) ($targetSourceEntityId ?? 'null'),
                        (string) ($targets['target_fcr_ratio'] ?? ''),
                        (string) ($targets['target_kb_usage_ratio'] ?? ''),
                        (string) ($targets['target_kb_quality'] ?? ''),
                    ]);

                    $uxCategoryEnrichments[$uxCategoryKey]
                        ['target_variants'][$variantKey] ??= [
                            'source_entity_id' =>
                                $targetSourceEntityId,

                            'source_entity_name' =>
                                $targetSourceEntityName,

                            'target_fcr_ratio' =>
                                $targets['target_fcr_ratio'] ?? null,

                            'target_fcr_percent' =>
                                $targets['target_fcr_percent'] ?? null,

                            'target_kb_usage_ratio' =>
                                $targets['target_kb_usage_ratio'] ?? null,

                            'target_kb_usage_percent' =>
                                $targets['target_kb_usage_percent'] ?? null,

                            'target_kb_quality' =>
                                $targets['target_kb_quality'] ?? null,

                            'tickets' => 0,
                        ];

                        $uxCategoryEnrichments[$uxCategoryKey]
                        ['target_variants'][$variantKey]
                        ['tickets']++;
                }

                if (
                    is_array($uxTicket['invalid_target_overrides'] ?? null)
                     && $uxTicket['invalid_target_overrides'] !== []
                ) {
                    $uxCategoryEnrichments[$uxCategoryKey]
                        ['tickets_with_invalid_target_override']++;
                }
            }

            /*
             * ---------------------
             * Channel
             * ---------------------
             */
            $requestTypeId = (int) ($ticket['requesttypes_id'] ?? 0);

            if ($requestTypeId <= 0) {
                $channelLabel = '(Non renseigné)';
            } else {
                if (!array_key_exists($requestTypeId, $requestTypeNames)) {
                    $requestTypeNames[$requestTypeId] =
                        $this->lookupName('glpi_requesttypes', $requestTypeId);
                }

                $channelLabel = trim((string) (
                    $requestTypeNames[$requestTypeId]
                    ?? ''
                ));

                if ($channelLabel === '') {
                    $channelLabel = '(Non renseigné)';
                }
            }

            $channelCounts[$channelLabel] =
                ($channelCounts[$channelLabel] ?? 0) + 1;

            /*
             * ---------------------
             * Location
             * ---------------------
             */
            $locationId = (int) ($ticket['locations_id'] ?? 0);

            if ($locationId <= 0) {
                $locationLabel = '(Non renseigné)';
            } else {
                if (!array_key_exists($locationId, $locationNames)) {
                    $locationNames[$locationId] =
                        $this->lookupName('glpi_locations', $locationId);
                }

                $locationLabel = trim((string) (
                    $locationNames[$locationId]
                    ?? ''
                ));

                if ($locationLabel === '') {
                    $locationLabel = '(Non renseigné)';
                }
            }

            $locationCounts[$locationLabel] =
                ($locationCounts[$locationLabel] ?? 0) + 1;

            /*
             * ---------------------
             * Resolution duration
             * ---------------------
             *
             * Preserve Incidentologue's historical definition:
             * raw creation timestamp -> raw solvedate timestamp.
             *
             * This metric is intentionally different from GLPI working-time
             * solve_delay_stat and from SLA TTR.
             */
            $creationTimestamp = $this->timestamp(
                $ticket['date'] ?? $ticket['date_creation'] ?? null,
            );

            $resolutionTimestamp = $this->timestamp(
                $ticket['solvedate'] ?? null,
            );

            if (
                $creationTimestamp !== null
                 && $resolutionTimestamp !== null
                 && $resolutionTimestamp >= $creationTimestamp
            ) {
                $resolutionHoursSum +=
                    ($resolutionTimestamp - $creationTimestamp) / 3600;

                $ticketsWithValidDates++;
            }

            /*
             * ---------------------
             * Temporal volumes
             * ---------------------
             */
            if ($creationTimestamp !== null) {
                $monthKey = date('Y-m', $creationTimestamp);
                $ticketsByMonth[$monthKey] =
                    ($ticketsByMonth[$monthKey] ?? 0) + 1;

                $dayOfWeek = (int) date('w', $creationTimestamp);

                $ticketsByDayOfWeek[$dayOfWeek] =
                    ($ticketsByDayOfWeek[$dayOfWeek] ?? 0) + 1;

                $dateKey = date('Y-m-d', $creationTimestamp);

                $distinctDatesByDayOfWeek[$dayOfWeek] ??= [];
                $distinctDatesByDayOfWeek[$dayOfWeek][$dateKey] = true;
            } else {
                $invalidCreationDatesMonth++;
            }
        }

        arsort($igQualificationCounts);
        arsort($igParentTypeCounts);
        arsort($uxSupportLevelCounts);

        foreach ($uxCategoryEnrichments as &$uxCategory) {
            arsort($uxCategory['support_levels']);

            $uxCategory['support_level_sources'] =
                array_values($uxCategory['support_level_sources']);

            usort(
                $uxCategory['support_level_sources'],
                static fn(array $a, array $b): int =>
                     ($b['tickets'] <=> $a['tickets'])
                     ?: (
                         (int) $a['entity_id']
                         <=> (int) $b['entity_id']
                     ),
            );

            $uxCategory['target_variants'] =
                array_values($uxCategory['target_variants']);

            usort(
                $uxCategory['target_variants'],
                static fn(array $a, array $b): int =>
                     ($b['tickets'] <=> $a['tickets'])
                     ?: (
                         (int) ($a['source_entity_id'] ?? 0)
                         <=> (int) ($b['source_entity_id'] ?? 0)
                     ),
            );
        }

        unset($uxCategory);

        $uxCategoryEnrichments =
            array_values($uxCategoryEnrichments);

        usort(
            $uxCategoryEnrichments,
            static fn(array $a, array $b): int =>
                 ($b['tickets'] <=> $a['tickets'])
                 ?: strcmp(
                     (string) $a['category_name'],
                     (string) $b['category_name'],
                 ),
        );

        /*
         * Pareto categories.
         */
        $paretoCategories = $this->incidentologyPareto(
            $categoryCounts,
            $ticketCount,
        );

        $topCategories = array_map(
            static fn(array $item): array => [
                'category' => $item['label'],
                'count' => $item['count'],
                'percentage' => $item['percentage'],
                'cumulativePercentage' => $item['cumulativePercentage'],
                'paretoFlag' => $item['paretoFlag'],
            ],
            $paretoCategories,
        );

        $dominantCategory = null;

        if ($topCategories !== []) {
            $dominantCategory = [
                'category' => $topCategories[0]['category'],
                'count' => $topCategories[0]['count'],
                'percent' => $topCategories[0]['percentage'],
            ];
        }

        /*
         * Pareto subcategories per category.
         */
        $subCategoriesByCategory = [];

        foreach ($subCategoryCountsByCategory as $category => $subCounts) {
            $categoryTotal = (int) ($categoryCounts[$category] ?? 0);

            $paretoSubcategories = $this->incidentologyPareto(
                $subCounts,
                $categoryTotal,
            );

            $subCategoriesByCategory[] = [
                'category' => $category,
                'categoryTotal' => $categoryTotal,
                'subCategories' => array_map(
                    static fn(array $item): array => [
                        'subCategory' => $item['label'],
                        'count' => $item['count'],
                        'percent' => $item['percentage'],
                        'cumulativePercentage' => $item['cumulativePercentage'],
                        'paretoFlag' => $item['paretoFlag'],
                    ],
                    $paretoSubcategories,
                ),
            ];
        }

        usort(
            $subCategoriesByCategory,
            static fn(array $a, array $b): int =>
                 ($b['categoryTotal'] <=> $a['categoryTotal'])
                 ?: strcmp((string) $a['category'], (string) $b['category']),
        );

        /*
         * Channels.
         */
        $channels = [];

        foreach ($channelCounts as $channel => $count) {
            $channels[] = [
                'channel' => $channel,
                'count' => (int) $count,
            ];
        }

        usort(
            $channels,
            static fn(array $a, array $b): int =>
                 ($b['count'] <=> $a['count'])
                 ?: strcmp((string) $a['channel'], (string) $b['channel']),
        );

        /*
         * Locations.
         */
        $volumeByLocation = [];

        foreach ($locationCounts as $location => $count) {
            $volumeByLocation[] = [
                'location' => $location,
                'count' => (int) $count,
            ];
        }

        usort(
            $volumeByLocation,
            static fn(array $a, array $b): int =>
                 ($b['count'] <=> $a['count'])
                 ?: strcmp((string) $a['location'], (string) $b['location']),
        );

        /*
         * Monthly volume.
         */
        ksort($ticketsByMonth, SORT_STRING);

        $ticketsByMonthArray = [];

        foreach ($ticketsByMonth as $month => $count) {
            $ticketsByMonthArray[] = [
                'month' => $month,
                'count' => (int) $count,
            ];
        }

        /*
         * Weekday averages.
         */
        $dayLabels = [
            0 => 'Dimanche',
            1 => 'Lundi',
            2 => 'Mardi',
            3 => 'Mercredi',
            4 => 'Jeudi',
            5 => 'Vendredi',
            6 => 'Samedi',
        ];

        $dayOrder = [1, 2, 3, 4, 5, 6, 0];

        $ticketsByDayOfWeekArray = [];

        foreach ($dayOrder as $dayIndex) {
            $totalForDay = (int) (
                $ticketsByDayOfWeek[$dayIndex]
                ?? 0
            );

            $distinctDatesCount = count(
                $distinctDatesByDayOfWeek[$dayIndex]
                ?? [],
            );

            $ticketsByDayOfWeekArray[] = [
                'dayOfWeek' => $dayLabels[$dayIndex],
                'totalTickets' => $totalForDay,
                'distinctDatesCount' => $distinctDatesCount,
                'averageTicketsPerDay' =>
                    $distinctDatesCount > 0
                        ? round($totalForDay / $distinctDatesCount, 1)
                        : null,
            ];
        }

        /*
         * Resolution metrics.
         */
        $averageResolutionHours =
            $ticketsWithValidDates > 0
                ? round(
                    $resolutionHoursSum / $ticketsWithValidDates,
                    1,
                )
                : null;

        $ratePercent =
            $ticketCount > 0
                ? round(($resolvedInternally / $ticketCount) * 100, 1)
                : 0.0;

        $resolutionAttributionEvaluable =
            (int) ($resolutionSources['historical_log'] ?? 0)
            + (int) ($resolutionSources['current_group_fallback'] ?? 0);

        $resolutionHistoricalCount =
            (int) ($resolutionSources['historical_log'] ?? 0);

        return [
            'success' => true,

            'period' => $this->period($criteria),

            'scope' => [
                'group_semantics' => 'initial_technical_assignment_group',
                'group_id' => $groupId,
                'group_name' => $groupName,
            ],

            'groupName' => $groupName,
            'groupId' => $groupId,

            'ticketCount' => $ticketCount,

            'resolutionRate' => [
                'resolvedInternally' => $resolvedInternally,
                'total' => $ticketCount,
                'ratePercent' => $ratePercent,
            ],

            'topCategories' => $topCategories,
            'subCategoriesByCategory' => $subCategoriesByCategory,
            'dominantCategory' => $dominantCategory,

            /*
             * Preserve the original Incidentologue visionIG contract.
             */
            'visionIG' => [
                'parentIncidentCount' => count($igParentIds),
                'consolidatedTicketCount' => $igConsolidatedTicketCount,
            ],

            /*
             * UXConnector enrichment beyond the former Excel contract.
             */
            'igmanager' => [
                'available' => (bool) ($igDataset['available'] ?? false),
                'qualifiedTicketCount' => $igQualifiedTicketCount,
                'qualificationCounts' => $igQualificationCounts,
                'consolidatedTicketCount' => $igConsolidatedTicketCount,
                'distinctParentIncidentCount' => count($igParentIds),
                'consolidatedByParentType' => $igParentTypeCounts,
            ],

            'uxknowdash' => [
                'available' =>
                    (bool) ($uxKnowDashDataset['available'] ?? false),

                'supportMatrixAvailable' =>
                    (bool) (
                        $uxKnowDashDataset['support_matrix_available']
                        ?? false
                    ),

                'categoryTargetsAvailable' =>
                    (bool) (
                        $uxKnowDashDataset['category_targets_available']
                        ?? false
                    ),

                'supportLevelCoverage' => [
                    'mappedTickets' =>
                        $uxSupportLevelMappedTickets,

                    'unmappedTickets' =>
                        $ticketCount - $uxSupportLevelMappedTickets,

                    'coveragePercent' =>
                        round(($uxSupportLevelMappedTickets / $ticketCount) * 100, 2),

                    'inheritedTickets' =>
                        $uxInheritedSupportLevelTickets,

                    'levels' =>
                        $uxSupportLevelCounts,
                ],

                'categoryTargetCoverage' => [
                    'mappedTickets' =>
                        $uxTargetMappedTickets,

                    'unmappedTickets' =>
                        $ticketCount - $uxTargetMappedTickets,

                    'coveragePercent' =>
                        round(($uxTargetMappedTickets / $ticketCount) * 100, 2),

                    'inheritedTickets' =>
                        $uxInheritedTargetTickets,

                    'ticketsWithInvalidOverride' =>
                        $uxTicketsWithInvalidTargetOverride,
                ],

                'categoryEnrichments' =>
                    $uxCategoryEnrichments,

                'invalidTargetRowsCount' =>
                    count(
                        $uxKnowDashDataset['invalid_target_rows']
                        ?? [],
                    ),
            ],

            'resolution' => [
                'averageResolutionHours' => $averageResolutionHours,
                'ticketsWithValidDates' => $ticketsWithValidDates,
            ],

            'channels' => $channels,
            'volumeByLocation' => $volumeByLocation,

            'ticketsByMonth' => $ticketsByMonthArray,
            'ticketsByDayOfWeek' => $ticketsByDayOfWeekArray,
            'invalidCreationDatesMonth' => $invalidCreationDatesMonth,

            'dataQuality' => array_merge(
                $baseDataQuality,
                [
                    'scoped_tickets' => $ticketCount,
                    'resolved_or_closed_in_scope' => $resolvedOrClosedInScope,

                    'igmanager_available' =>
                        (bool) ($igDataset['available'] ?? false),

                    'igmanager_scope_qualified_tickets' =>
                        $igQualifiedTicketCount,

                    'igmanager_scope_consolidated_tickets' =>
                        $igConsolidatedTicketCount,

                    'igmanager_global_active_qualifications' =>
                        (int) ($igDataset['active_qualifications_total'] ?? 0),

                    'igmanager_global_orphan_active_qualifications' =>
                        (int) ($igDataset['orphan_active_qualifications'] ?? 0),

                    'igmanager_global_active_relations' =>
                        (int) ($igDataset['active_relations_total'] ?? 0),

                    'igmanager_global_orphan_active_relations' =>
                        (int) ($igDataset['orphan_active_relations'] ?? 0),

                    'uxknowdash_available' =>
                        (bool) (
                            $uxKnowDashDataset['available']
                            ?? false
                        ),

                    'uxknowdash_support_matrix_rows_for_group' =>
                        (int) (
                            $uxKnowDashDataset['support_matrix_rows_total']
                            ?? 0
                        ),

                    'uxknowdash_category_target_rows' =>
                        (int) (
                            $uxKnowDashDataset['category_target_rows_total']
                            ?? 0
                        ),

                    'uxknowdash_invalid_target_rows' =>
                        count(
                            $uxKnowDashDataset['invalid_target_rows']
                            ?? [],
                        ),

                    'uxknowdash_scope_support_level_mapped_tickets' =>
                        $uxSupportLevelMappedTickets,

                    'uxknowdash_scope_target_mapped_tickets' =>
                        $uxTargetMappedTickets,

                    'uxknowdash_scope_tickets_with_invalid_target_override' =>
                        $uxTicketsWithInvalidTargetOverride,

                    'resolution_group_sources' => $resolutionSources,

                    'resolution_attribution_effective_coverage_percent' =>
                        $resolvedOrClosedInScope > 0
                            ? round(
                                ($resolutionAttributionEvaluable / $resolvedOrClosedInScope) * 100,
                                2,
                            )
                            : null,

                    'resolution_attribution_historical_coverage_percent' =>
                        $resolvedOrClosedInScope > 0
                            ? round(
                                ($resolutionHistoricalCount / $resolvedOrClosedInScope) * 100,
                                2,
                            )
                            : null,
                ],
            ),

            'dataSources' => [
                'glpi' => true,
                'group_history' => true,
                'igmanager' => (bool) ($igDataset['available'] ?? false),
                'uxknowdash' =>
                    (bool) ($uxKnowDashDataset['available'] ?? false),
            ],

            'metricDefinitions' => [
                'ticketCount' =>
                    'Tickets whose resolved initial technical assignment group matches the analysed group.',
                'resolutionRate' =>
                    'Internal autonomy metric: tickets whose resolved resolution group equals the analysed '
                        . 'initial group, divided by all tickets in that group scope. This is not SLA compliance.',
                'category' =>
                    'GLPI immediate parent ITIL category when available; otherwise the ticket category itself.',
                'subCategory' =>
                    'GLPI leaf ITIL category when a parent exists; otherwise "(Non renseigné)".',
                'averageResolutionHours' =>
                    'Average raw calendar duration between ticket creation date and solvedate for tickets with '
                        . 'both valid timestamps. This is not GLPI SLA TTR and not solve_delay_stat.',
                'groupAttribution' =>
                    'Historical technical-group logs are preferred. A unique current technical group is used '
                        . 'explicitly as fallback when usable history is absent.',

                'visionIG' =>
                    'When IGManager is available, parentIncidentCount is the number of distinct active valid '
                        . 'IG/IP parents referenced by tickets in scope, and consolidatedTicketCount is the '
                        . 'number of scoped tickets having an active valid IGManager parent relation.',

                'igmanagerQualifications' =>
                    'IG/IP/IC qualifications are read only from active IGManager rows referencing an existing '
                        . 'non-deleted GLPI ticket. Orphan active rows are excluded from metrics and reported in '
                        . 'dataQuality.',

                'uxknowdashSupportLevel' =>
                    'Support level is a UXKnowDash group/category reference value. The exact ticket entity is '
                        . 'preferred, then the nearest ancestor entity. It is descriptive metadata, not a '
                        . 'measured performance result.',

                'uxknowdashTargets' =>
                    'FCR and KB usage targets are valid only when stored as ratios between 0 and 1; KB quality is '
                        . 'valid only between 0 and 5. Invalid overrides are never normalized silently: they are '
                        . 'ignored, reported, and inheritance continues to the nearest valid ancestor target.',

                'uxknowdashTargetComparison' =>
                    'UXKnowDash FCR targets are not compared with Incidentologue resolutionRate because internal '
                        . 'resolution/autonomy and FCR are different metrics.',
            ],
        ];
    }


    /**
     * Incidentologue analysis by validated qualitative typology mapping.
     *
     * The mapping applies to the Incidentologue category label:
     * - immediate parent ITIL category when a parent exists;
     * - otherwise the ticket ITIL category itself.
     *
     * If no mapping is supplied, this method returns the categories that must
     * be mapped before analysis. This preserves the historical Incidentologue
     * workflow where the user validates category -> typology classification.
     *
     * Accepted mapping formats:
     * - typology_mapping object:
     *   {"Category A":"Accès","Category B":"Bureautique"}
     * - typology_mapping list:
     *   [{"category":"Category A","typology":"Accès"}, ...]
     * - typology_mapping_json containing either representation.
     *
     * @param array<string,mixed> $criteria
     *
     * @return array<string,mixed>
     */
    public function incidentologyTypologies(array $criteria): array
    {
        $groupId = (int) ($criteria['group_id'] ?? 0);

        if ($groupId <= 0) {
            return [
                'success' => false,
                'error' => 'group_id is required and must be greater than 0.',
                'period' => $this->period($criteria),
            ];
        }

        $groupName = $this->lookupName('glpi_groups', $groupId)
            ?? ('Group #' . $groupId);

        $allowedTypologies = [
            'Accès',
            'Bureautique',
            'Matériel',
            'Réseau',
            'Téléphonie',
            'Application Métiers',
            'Non classifiée',
        ];

        $normalize = static function (string $value): string {
            $value = trim($value);
            return function_exists('mb_strtolower')
                ? mb_strtolower($value, 'UTF-8')
                : strtolower($value);
        };

        /*
        * Resolve the same group scope as incidentologyGlobal().
        */
        $candidateTickets = $this->filteredTickets($criteria);
        $attributions = $this->incidentologyGroupAttributions($candidateTickets);

        $initialSources = [
            'historical_log' => 0,
            'current_group_fallback' => 0,
            'ambiguous' => 0,
            'unavailable' => 0,
        ];

        $scopeTickets = [];

        foreach ($candidateTickets as $ticket) {
            $ticketId = (int) ($ticket['id'] ?? 0);
            if ($ticketId <= 0) {
                continue;
            }

            $attribution = $attributions[$ticketId] ?? null;
            if (!is_array($attribution)) {
                $initialSources['unavailable']++;
                continue;
            }

            $source = (string) (
                $attribution['initial_assignment_source']
                ?? 'unavailable'
            );

            if (!array_key_exists($source, $initialSources)) {
                $initialSources[$source] = 0;
            }
            $initialSources[$source]++;

            $initialGroup = $attribution['initial_assignment_group'] ?? null;

            if (
                is_array($initialGroup)
                && (int) ($initialGroup['id'] ?? 0) === $groupId
            ) {
                $scopeTickets[] = $ticket;
            }
        }

        $candidateCount = count($candidateTickets);
        $effectiveInitialAttribution =
          (int) ($initialSources['historical_log'] ?? 0)
          + (int) ($initialSources['current_group_fallback'] ?? 0);

        $historicalInitialCount =
          (int) ($initialSources['historical_log'] ?? 0);

        $baseDataQuality = [
            'candidate_tickets' => $candidateCount,
            'initial_assignment_sources' => $initialSources,
            'initial_assignment_effective_coverage_percent' =>
                $candidateCount > 0
                    ? round(($effectiveInitialAttribution / $candidateCount) * 100, 2)
                    : null,
            'initial_assignment_historical_coverage_percent' =>
                $candidateCount > 0
                    ? round(($historicalInitialCount / $candidateCount) * 100, 2)
                    : null,
        ];

        if ($scopeTickets === []) {
            return [
                'success' => false,
                'error' => sprintf(
                    "No tickets found for initial technical group '%s' (%d) in the requested scope.",
                    $groupName,
                    $groupId,
                ),
                'period' => $this->period($criteria),
                'group' => [
                    'id' => $groupId,
                    'name' => $groupName,
                ],
                'ticketCount' => 0,
                'dataQuality' => $baseDataQuality,
            ];
        }

        /*
        * Resolve Incidentologue category/subcategory labels once per ticket.
        */
        $categoryMetadataCache = [];
        $ticketLabels = [];
        $categoryCounts = [];

        foreach ($scopeTickets as $ticket) {
            $ticketId = (int) ($ticket['id'] ?? 0);
            $categoryId = (int) ($ticket['itilcategories_id'] ?? 0);

            if (!array_key_exists($categoryId, $categoryMetadataCache)) {
                $categoryMetadataCache[$categoryId] =
                    $this->categoryMetadata($categoryId);
            }

            $metadata = $categoryMetadataCache[$categoryId];

            if ($categoryId <= 0) {
                $categoryLabel = '(Non renseigné)';
                $subCategoryLabel = '(Non renseigné)';
            } else {
                $categoryName = trim((string) (
                    $metadata['category_name']
                  ?? ('Category #' . $categoryId)
                ));

                $parentCategoryName = trim((string) (
                    $metadata['parent_category_name']
                  ?? ''
                ));

                if ($parentCategoryName !== '') {
                    $categoryLabel = $parentCategoryName;
                    $subCategoryLabel =
                        $categoryName !== ''
                            ? $categoryName
                            : '(Non renseigné)';
                } else {
                    $categoryLabel =
                        $categoryName !== ''
                            ? $categoryName
                            : '(Non renseigné)';
                    $subCategoryLabel = '(Non renseigné)';
                }
            }

            $ticketLabels[$ticketId] = [
                'category' => $categoryLabel,
                'subcategory' => $subCategoryLabel,
            ];

            $categoryCounts[$categoryLabel] =
                ($categoryCounts[$categoryLabel] ?? 0) + 1;
        }

        $ticketCount = count($scopeTickets);
        $categoryPareto = $this->incidentologyPareto(
            $categoryCounts,
            $ticketCount,
        );

        $categoriesToMap = array_map(
            static fn(array $item): array => [
                'category' => $item['label'],
                'count' => $item['count'],
                'percentage' => $item['percentage'],
                'cumulativePercentage' => $item['cumulativePercentage'],
                'paretoFlag' => $item['paretoFlag'],
            ],
            $categoryPareto,
        );

        /*
         * Read and normalize mapping.
         */
        $rawMapping = $criteria['typology_mapping']
            ?? $criteria['typology_mapping_json']
            ?? null;

        if (is_string($rawMapping)) {
            $trimmed = trim($rawMapping);

            if ($trimmed === '') {
                $rawMapping = null;
            } else {
                try {
                    $rawMapping = json_decode(
                        $trimmed,
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                } catch (\JsonException $e) {
                    return [
                        'success' => false,
                        'status' => 'mapping_invalid',
                        'error' => 'typology_mapping_json is not valid JSON.',
                        'period' => $this->period($criteria),
                        'group' => [
                            'id' => $groupId,
                            'name' => $groupName,
                        ],
                        'ticketCount' => $ticketCount,
                        'allowedTypologies' => $allowedTypologies,
                        'categoriesToMap' => $categoriesToMap,
                        'dataQuality' => $baseDataQuality,
                    ];
                }
            }
        }

        if (!is_array($rawMapping) || $rawMapping === []) {
            return [
                'success' => true,
                'status' => 'mapping_required',
                'period' => $this->period($criteria),
                'group' => [
                    'id' => $groupId,
                    'name' => $groupName,
                ],
                'groupName' => $groupName,
                'groupId' => $groupId,
                'ticketCount' => $ticketCount,
                'allowedTypologies' => $allowedTypologies,
                'categoriesToMap' => $categoriesToMap,
                'instructions' => [
                    'Propose a qualitative mapping only for categories that can be classified with confidence.',
                    'Ask the user to validate or correct the mapping before running the final typology analysis.',
                    'Use "Non classifiée" explicitly for a category the user chooses not to classify.',
                ],
                'dataQuality' => array_merge(
                    $baseDataQuality,
                    ['scoped_tickets' => $ticketCount],
                ),
            ];
        }

        $allowedByNormalized = [];
        foreach ($allowedTypologies as $typology) {
            $allowedByNormalized[$normalize($typology)] = $typology;
        }

        $mapping = [];
        $invalidMappings = [];

        if (array_is_list($rawMapping)) {
            foreach ($rawMapping as $entry) {
                if (!is_array($entry)) {
                    $invalidMappings[] = [
                        'category' => null,
                        'typology' => null,
                        'reason' => 'mapping entry must be an object',
                    ];
                    continue;
                }

                $category = trim((string) ($entry['category'] ?? ''));
                $typology = trim((string) ($entry['typology'] ?? ''));

                if ($category === '' || $typology === '') {
                    $invalidMappings[] = [
                        'category' => $category !== '' ? $category : null,
                        'typology' => $typology !== '' ? $typology : null,
                        'reason' => 'category and typology are required',
                    ];
                    continue;
                }

                $canonical = $allowedByNormalized[$normalize($typology)] ?? null;

                if ($canonical === null) {
                    $invalidMappings[] = [
                        'category' => $category,
                        'typology' => $typology,
                        'reason' => 'unsupported typology',
                    ];
                    continue;
                }

                $mapping[$normalize($category)] = [
                    'category' => $category,
                    'typology' => $canonical,
                ];
            }
        } else {
            foreach ($rawMapping as $category => $typology) {
                $category = trim((string) $category);
                $typology = trim((string) $typology);

                if ($category === '' || $typology === '') {
                    $invalidMappings[] = [
                        'category' => $category !== '' ? $category : null,
                        'typology' => $typology !== '' ? $typology : null,
                        'reason' => 'category and typology are required',
                    ];
                    continue;
                }

                $canonical = $allowedByNormalized[$normalize($typology)] ?? null;

                if ($canonical === null) {
                    $invalidMappings[] = [
                        'category' => $category,
                        'typology' => $typology,
                        'reason' => 'unsupported typology',
                    ];
                    continue;
                }

                $mapping[$normalize($category)] = [
                    'category' => $category,
                    'typology' => $canonical,
                ];
            }
        }

        $unmappedCategories = [];

        foreach ($categoryCounts as $category => $count) {
            if (!isset($mapping[$normalize((string) $category)])) {
                $unmappedCategories[] = [
                    'category' => (string) $category,
                    'count' => (int) $count,
                ];
            }
        }

        usort(
            $unmappedCategories,
            static fn(array $a, array $b): int =>
                 ($b['count'] <=> $a['count'])
                 ?: strcmp((string) $a['category'], (string) $b['category']),
        );

        if ($invalidMappings !== []) {
            return [
                'success' => false,
                'status' => 'mapping_invalid',
                'error' => 'typology_mapping contains invalid entries.',
                'period' => $this->period($criteria),
                'group' => [
                    'id' => $groupId,
                    'name' => $groupName,
                ],
                'ticketCount' => $ticketCount,
                'allowedTypologies' => $allowedTypologies,
                'categoriesToMap' => $categoriesToMap,
                'invalidMappings' => $invalidMappings,
                'unmappedCategories' => $unmappedCategories,
                'dataQuality' => array_merge(
                    $baseDataQuality,
                    ['scoped_tickets' => $ticketCount],
                ),
            ];
        }

        $scopeTicketIds = array_values(array_map(
            static fn(array $ticket): int => (int) ($ticket['id'] ?? 0),
            $scopeTickets,
        ));

        $igDataset = $this->incidentologyIgManagerDataset($scopeTicketIds);
        $uxDataset = $this->incidentologyUxKnowDashDataset(
            $scopeTickets,
            $groupId,
        );

        $requestTypeNames = [];
        $locationNames = [];
        /**
         * @var array<string,array{
         *     ticketCount:int,
         *     subcategoryCounts:array<string,int>,
         *     channelCounts:array<string,int>,
         *     locationCounts:array<string,int>,
         *     resolvedInternally:int,
         *     resolutionHoursSum:float,
         *     ticketsWithValidDates:int,
         *     parentIds:array<int,true>,
         *     consolidatedTicketCount:int,
         *     qualificationCounts:array<string,int>,
         *     supportLevelCounts:array<string,int>,
         *     supportLevelMappedTickets:int
         * }> $buckets
         */
        $buckets = [];
        $typologyCounts = [];

        foreach ($scopeTickets as $ticket) {
            $ticketId = (int) ($ticket['id'] ?? 0);
            $labels = $ticketLabels[$ticketId] ?? [
                'category' => '(Non renseigné)',
                'subcategory' => '(Non renseigné)',
            ];

            $mappingEntry =
                $mapping[$normalize((string) $labels['category'])]
                ?? [
                    'category' => (string) $labels['category'],
                    'typology' => 'Non classifiée',
                ];

            $typology = (string) $mappingEntry['typology'];

            if (!isset($buckets[$typology])) {
                $buckets[$typology] = [
                    'ticketCount' => 0,
                    'subcategoryCounts' => [],
                    'channelCounts' => [],
                    'locationCounts' => [],
                    'resolvedInternally' => 0,
                    'resolutionHoursSum' => 0.0,
                    'ticketsWithValidDates' => 0,
                    'parentIds' => [],
                    'consolidatedTicketCount' => 0,
                    'qualificationCounts' => [],
                    'supportLevelCounts' => [],
                    'supportLevelMappedTickets' => 0,
                ];
            }

            $bucket = & $buckets[$typology];
            $bucket['ticketCount']++;
            $typologyCounts[$typology] =
                ($typologyCounts[$typology] ?? 0) + 1;

            $subCategory = (string) $labels['subcategory'];
            $bucket['subcategoryCounts'][$subCategory] =
                ($bucket['subcategoryCounts'][$subCategory] ?? 0) + 1;

            $attribution = $attributions[$ticketId] ?? [];
            $resolutionGroup = is_array($attribution)
                ? ($attribution['resolution_group'] ?? null)
                : null;

            if (
                is_array($resolutionGroup)
                && (int) ($resolutionGroup['id'] ?? 0) === $groupId
            ) {
                $bucket['resolvedInternally']++;
            }

            $creationTimestamp = $this->timestamp(
                $ticket['date'] ?? $ticket['date_creation'] ?? null,
            );
            $resolutionTimestamp = $this->timestamp(
                $ticket['solvedate'] ?? null,
            );

            if (
                $creationTimestamp !== null
                 && $resolutionTimestamp !== null
                 && $resolutionTimestamp >= $creationTimestamp
            ) {
                $bucket['resolutionHoursSum'] +=
                  ($resolutionTimestamp - $creationTimestamp) / 3600;
                $bucket['ticketsWithValidDates']++;
            }

            $requestTypeId = (int) ($ticket['requesttypes_id'] ?? 0);
            if ($requestTypeId <= 0) {
                $channel = '(Non renseigné)';
            } else {
                if (!array_key_exists($requestTypeId, $requestTypeNames)) {
                    $requestTypeNames[$requestTypeId] =
                        $this->lookupName('glpi_requesttypes', $requestTypeId);
                }

                $channel = trim((string) (
                    $requestTypeNames[$requestTypeId] ?? ''
                ));
                if ($channel === '') {
                    $channel = '(Non renseigné)';
                }
            }

            $bucket['channelCounts'][$channel] =
                ($bucket['channelCounts'][$channel] ?? 0) + 1;

            $locationId = (int) ($ticket['locations_id'] ?? 0);
            if ($locationId <= 0) {
                $location = '(Non renseigné)';
            } else {
                if (!array_key_exists($locationId, $locationNames)) {
                    $locationNames[$locationId] =
                        $this->lookupName('glpi_locations', $locationId);
                }

                $location = trim((string) (
                    $locationNames[$locationId] ?? ''
                ));
                if ($location === '') {
                    $location = '(Non renseigné)';
                }
            }

            $bucket['locationCounts'][$location] =
                ($bucket['locationCounts'][$location] ?? 0) + 1;

            if ($igDataset['available'] ?? false) {
                $qualification = $igDataset['qualifications'][$ticketId] ?? null;
                if (is_array($qualification)) {
                    $incidentType = trim((string) (
                        $qualification['incident_type'] ?? ''
                    ));
                    if ($incidentType === '') {
                        $incidentType = '(Non renseigné)';
                    }

                    $bucket['qualificationCounts'][$incidentType] =
                        ($bucket['qualificationCounts'][$incidentType] ?? 0) + 1;
                }

                $parent = $igDataset['parent_relations'][$ticketId] ?? null;
                if (is_array($parent)) {
                    $parentId = (int) ($parent['parent_ticket_id'] ?? 0);
                    if ($parentId > 0) {
                        $bucket['parentIds'][$parentId] = true;
                    }
                    $bucket['consolidatedTicketCount']++;
                }
            }

            $uxTicket = $uxDataset['tickets'][$ticketId] ?? null;
            if (is_array($uxTicket)) {
                $supportLevel = trim((string) (
                    $uxTicket['support_level'] ?? ''
                ));

                if ($supportLevel !== '') {
                    $bucket['supportLevelCounts'][$supportLevel] =
                      ($bucket['supportLevelCounts'][$supportLevel] ?? 0) + 1;
                    $bucket['supportLevelMappedTickets']++;
                }
            }

            unset($bucket);
        }

        $typologyPareto = $this->incidentologyPareto(
            $typologyCounts,
            $ticketCount,
        );

        $sortedCountList = static function (
            array $counts,
            string $labelKey
        ): array {
            $rows = [];
            foreach ($counts as $label => $count) {
                $rows[] = [
                    $labelKey => (string) $label,
                    'count' => (int) $count,
                ];
            }

            usort(
                $rows,
                static fn(array $a, array $b): int =>
                     ($b['count'] <=> $a['count'])
                     ?: strcmp(
                         (string) $a[$labelKey],
                         (string) $b[$labelKey],
                     ),
            );

            return $rows;
        };

        $typologies = [];

        foreach ($typologyPareto as $paretoItem) {
            $typology = (string) $paretoItem['label'];
            $bucket = $buckets[$typology];
            $count = (int) $bucket['ticketCount'];

            $subPareto = $this->incidentologyPareto(
                $bucket['subcategoryCounts'],
                $count,
            );

            $topSubcategories = array_map(
                static fn(array $item): array => [
                    'subcategory' => $item['label'],
                    'count' => $item['count'],
                    'percentage' => $item['percentage'],
                    'cumulativePercentage' => $item['cumulativePercentage'],
                    'paretoFlag' => $item['paretoFlag'],
                ],
                $subPareto,
            );

            $averageResolutionHours =
               $bucket['ticketsWithValidDates'] > 0
                   ? round(
                       $bucket['resolutionHoursSum']
                       / $bucket['ticketsWithValidDates'],
                       1,
                   )
                   : null;

            $ratePercent =
               $count > 0
                   ? round(
                       ($bucket['resolvedInternally'] / $count) * 100,
                       1,
                   )
                   : 0.0;

            arsort($bucket['qualificationCounts']);
            arsort($bucket['supportLevelCounts']);

            $typologies[] = [
                'typologyName' => $typology,
                'ticketCount' => $count,
                'percentage' => $paretoItem['percentage'],
                'cumulativePercentage' =>
                    $paretoItem['cumulativePercentage'],
                'paretoFlag' => $paretoItem['paretoFlag'],
                'topSubcategories' => $topSubcategories,
                'resolutionRate' => [
                    'resolvedInternally' =>
                        (int) $bucket['resolvedInternally'],
                    'total' => $count,
                    'ratePercent' => $ratePercent,
                ],
                'visionIG' => [
                    'parentIncidentCount' =>
                        count($bucket['parentIds']),
                    'consolidatedTicketCount' =>
                        (int) $bucket['consolidatedTicketCount'],
                ],
                'resolution' => [
                    'averageResolutionHours' =>
                        $averageResolutionHours,
                    'ticketsWithValidDates' =>
                        (int) $bucket['ticketsWithValidDates'],
                ],
                'channels' => $sortedCountList(
                    $bucket['channelCounts'],
                    'channel',
                ),
                'volumeByLocation' => $sortedCountList(
                    $bucket['locationCounts'],
                    'location',
                ),
                'igmanager' => [
                    'available' =>
                        (bool) ($igDataset['available'] ?? false),
                    'qualificationCounts' =>
                        $bucket['qualificationCounts'],
                ],
                'uxknowdash' => [
                    'available' =>
                        (bool) ($uxDataset['available'] ?? false),
                    'supportLevelCoverage' => [
                        'mappedTickets' =>
                            (int) $bucket['supportLevelMappedTickets'],
                        'unmappedTickets' =>
                            $count - (int) $bucket['supportLevelMappedTickets'],
                        'coveragePercent' =>
                            $count > 0
                                ? round(
                                    ((int) $bucket['supportLevelMappedTickets']
                                        / $count) * 100,
                                    2,
                                )
                                : null,
                        'levels' => $bucket['supportLevelCounts'],
                    ],
                ],
            ];
        }

        $dominantTypology = null;

        if ($typologies !== []) {
            $dominantTypology = [
                'typology' => $typologies[0]['typologyName'],
                'count' => $typologies[0]['ticketCount'],
                'percent' => $typologies[0]['percentage'],
            ];
        }

        $validatedMapping = [];
        foreach ($categoryCounts as $category => $count) {
            $entry =
                $mapping[$normalize((string) $category)]
                ?? [
                    'category' => (string) $category,
                    'typology' => 'Non classifiée',
                ];

            $validatedMapping[] = [
                'category' => (string) $category,
                'typology' => (string) $entry['typology'],
                'ticketCount' => (int) $count,
                'mappingSource' =>
                    isset($mapping[$normalize((string) $category)])
                        ? 'validated_mapping'
                        : 'unmapped_fallback',
            ];
        }

        usort(
            $validatedMapping,
            static fn(array $a, array $b): int =>
               ($b['ticketCount'] <=> $a['ticketCount'])
               ?: strcmp((string) $a['category'], (string) $b['category']),
        );

        return [
            'success' => true,
            'status' => 'analysis_complete',
            'period' => $this->period($criteria),
            'scope' => [
                'group_semantics' =>
                    'initial_technical_assignment_group',
                'group_id' => $groupId,
                'group_name' => $groupName,
            ],
            'groupName' => $groupName,
            'groupId' => $groupId,
            'ticketCount' => $ticketCount,
            'allowedTypologies' => $allowedTypologies,
            'validatedMapping' => $validatedMapping,
            'typologies' => $typologies,
            'dominantTypology' => $dominantTypology,
            'unmappedCategories' => $unmappedCategories,
            'dataQuality' => array_merge(
                $baseDataQuality,
                [
                    'scoped_tickets' => $ticketCount,
                    'mapped_categories' =>
                        count($categoryCounts) - count($unmappedCategories),
                    'unmapped_categories' => count($unmappedCategories),
                    'igmanager_available' =>
                        (bool) ($igDataset['available'] ?? false),
                    'igmanager_global_active_qualifications' =>
                        (int) ($igDataset['active_qualifications_total'] ?? 0),
                    'igmanager_global_orphan_active_qualifications' =>
                        (int) ($igDataset['orphan_active_qualifications'] ?? 0),
                    'igmanager_global_active_relations' =>
                        (int) ($igDataset['active_relations_total'] ?? 0),
                    'igmanager_global_orphan_active_relations' =>
                        (int) ($igDataset['orphan_active_relations'] ?? 0),
                    'uxknowdash_available' =>
                        (bool) ($uxDataset['available'] ?? false),
                ],
            ),
            'dataSources' => [
                'glpi' => true,
                'group_history' => true,
                'igmanager' =>
                    (bool) ($igDataset['available'] ?? false),
                'uxknowdash' =>
                    (bool) ($uxDataset['available'] ?? false),
            ],
            'metricDefinitions' => [
                'typology' =>
                    'Qualitative category-to-typology classification validated by the user. Categories omitted '
                        . 'from the supplied mapping are assigned to "Non classifiée" and reported in '
                        . 'unmappedCategories. This is not a semantic classification of ticket descriptions.',
                'ticketCount' =>
                    'Tickets whose resolved initial technical assignment group matches the analysed group.',
                'resolutionRate' =>
                    'Internal autonomy metric within each typology. This is not SLA compliance and not UXKnowDash FCR.',
                'averageResolutionHours' =>
                    'Average raw calendar duration between ticket creation and solvedate for tickets with valid '
                        . 'timestamps. This is not GLPI SLA TTR.',
                'pareto' =>
                    'Percentages are rounded to one decimal; cumulative percentage is based on rounded values; '
                        . 'paretoFlag remains true while cumulative percentage is <= 80%.',
            ],
        ];
    }

    /**
     * Compute the Incidentologue Pareto representation.
     *
     * This preserves the former Office Script behaviour:
     * - percentages rounded to one decimal;
     * - cumulative percentage based on those rounded percentages;
     * - Pareto flag true while rounded cumulative percentage is <= 80%.
     *
     * @param array<string,int> $counts
     *
     * @return list<array{
     *   label:string,
     *   count:int,
     *   percentage:float,
     *   cumulativePercentage:float,
     *   paretoFlag:bool
     * }>
     */
    private function incidentologyPareto(array $counts, int $total): array
    {
        if ($total <= 0 || $counts === []) {
            return [];
        }

        $items = [];

        foreach ($counts as $label => $count) {
            $count = (int) $count;

            $items[] = [
                'label' => (string) $label,
                'count' => $count,
                'percentage' => round(($count / $total) * 100, 1),
            ];
        }

        usort(
            $items,
            static fn(array $a, array $b): int =>
                 ($b['count'] <=> $a['count'])
                 ?: strcmp((string) $a['label'], (string) $b['label']),
        );

        $cumulative = 0.0;

        foreach ($items as &$item) {
            $cumulative += (float) $item['percentage'];

            $roundedCumulative = min(
                round($cumulative, 1),
                100.0,
            );

            $item['cumulativePercentage'] = $roundedCumulative;
            $item['paretoFlag'] = $roundedCumulative <= 80.0;
        }

        unset($item);

        return $items;
    }

    /**
     * Load optional IGManager incidentology enrichment for a ticket batch.
     *
     * Only active rows pointing to existing GLPI tickets are considered
     * usable. Orphan rows are counted for data-quality reporting.
     *
     * @param list<int> $ticketIds
     *
     * @return array{
     *   available:bool,
     *   qualifications:array<int,array<string,mixed>>,
     *   parent_relations:array<int,array<string,mixed>>,
     *   orphan_active_qualifications:int,
     *   orphan_active_relations:int,
     *   active_qualifications_total:int,
     *   active_relations_total:int
     * }
     */
    private function incidentologyIgManagerDataset(array $ticketIds): array
    {
        global $DB;

        $ticketIds = array_values(array_unique(array_filter(
            array_map('intval', $ticketIds),
            static fn(int $id): bool => $id > 0,
        )));

        $qualificationTable = 'glpi_plugin_igmanager_qualifications';
        $relationTable = 'glpi_plugin_igmanager_relations';

        $result = [
            'available' => false,
            'qualifications' => [],
            'parent_relations' => [],
            'orphan_active_qualifications' => 0,
            'orphan_active_relations' => 0,
            'active_qualifications_total' => 0,
            'active_relations_total' => 0,
        ];

        if (
            !$DB->tableExists($qualificationTable)
            || !$DB->tableExists($relationTable)
            || !$DB->tableExists('glpi_tickets')
        ) {
            return $result;
        }

        $result['available'] = true;

        /*
         * Build an existence map once. This also lets us distinguish an
         * IGManager row referencing a deleted/missing ticket from a valid row.
         */
        $existingTickets = [];

        foreach (
            $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_tickets',
            'WHERE' => [
                'is_deleted' => 0,
            ],
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $existingTickets[$id] = true;
            }
        }

        foreach (
            $DB->request([
            'FROM' => $qualificationTable,
            'WHERE' => [
                'is_active' => 1,
            ],
            'ORDER' => ['id ASC'],
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $result['active_qualifications_total']++;

            $ticketId = (int) ($row['tickets_id'] ?? 0);

            if (
                $ticketId <= 0
                 || !isset($existingTickets[$ticketId])
            ) {
                $result['orphan_active_qualifications']++;
                continue;
            }

            if (!in_array($ticketId, $ticketIds, true)) {
                continue;
            }

            $result['qualifications'][$ticketId] = [
                'ticket_id' => $ticketId,
                'incident_type' => trim((string) ($row['incident_type'] ?? '')),
                'scope_label' => trim((string) ($row['scope_label'] ?? '')),
                'escalation_group_id' => (int) ($row['escalation_group_id'] ?? 0),
                'created_by' => (int) ($row['created_by'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        foreach (
            $DB->request([
            'FROM' => $relationTable,
            'WHERE' => [
                'is_active' => 1,
            ],
            'ORDER' => ['id ASC'],
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $result['active_relations_total']++;

            $parentId = (int) ($row['parent_tickets_id'] ?? 0);
            $childId = (int) ($row['child_tickets_id'] ?? 0);

            if (
                $parentId <= 0
                 || $childId <= 0
                 || !isset($existingTickets[$parentId])
                 || !isset($existingTickets[$childId])
            ) {
                $result['orphan_active_relations']++;
                continue;
            }

            if (!in_array($childId, $ticketIds, true)) {
                continue;
            }

            $result['parent_relations'][$childId] = [
                'child_ticket_id' => $childId,
                'parent_ticket_id' => $parentId,
                'parent_type' => trim((string) ($row['parent_type'] ?? '')),
                'lifecycle_mode' => trim((string) ($row['lifecycle_mode'] ?? '')),
                'sync_followups' => (bool) ($row['sync_followups'] ?? false),
                'cascade_solution' => (bool) ($row['cascade_solution'] ?? false),
                'cascade_closure' => (bool) ($row['cascade_closure'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * Load optional UXKnowDash enrichment for an Incidentologue ticket batch.
     *
     * Entity inheritance:
     * exact ticket entity -> nearest parent -> root entity.
     *
     * Invalid category targets never get silently normalized. They are
     * reported and inheritance continues until a valid ancestor target is
     * found.
     *
     * @param list<array<string,mixed>> $tickets
     *
     * @return array{
     *   available:bool,
     *   support_matrix_available:bool,
     *   category_targets_available:bool,
     *   tickets:array<int,array<string,mixed>>,
     *   invalid_target_rows:list<array<string,mixed>>,
     *   support_matrix_rows_total:int,
     *   category_target_rows_total:int
     * }
     */
    private function incidentologyUxKnowDashDataset(
        array $tickets,
        int $groupId
    ): array {
        global $DB;

        $groupCategoryTable =
            'glpi_plugin_uxknowdash_groupcategories';

        $targetTable =
            'glpi_plugin_uxknowdash_category_targets';

        $result = [
            'available' => false,
            'support_matrix_available' => false,
            'category_targets_available' => false,
            'tickets' => [],
            'invalid_target_rows' => [],
            'support_matrix_rows_total' => 0,
            'category_target_rows_total' => 0,
        ];

        if (
            !$DB->tableExists('glpi_entities')
            || !$DB->tableExists('glpi_itilcategories')
        ) {
            return $result;
        }

        $supportAvailable =
            $DB->tableExists($groupCategoryTable);

        $targetsAvailable =
            $DB->tableExists($targetTable);

        $result['support_matrix_available'] =
            $supportAvailable;

        $result['category_targets_available'] =
            $targetsAvailable;

        $result['available'] =
            $supportAvailable || $targetsAvailable;

        if (!$result['available']) {
            return $result;
        }

        /*
         * Normalize tickets and collect used entity/category IDs.
         */
        $ticketsById = [];
        $wantedEntityIds = [];
        $wantedCategoryIds = [];

        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $ticketId = (int) ($ticket['id'] ?? 0);

            if ($ticketId <= 0) {
                continue;
            }

            $entityId =
                (int) ($ticket['entities_id'] ?? 0);

            $categoryId =
                (int) ($ticket['itilcategories_id'] ?? 0);

            $ticketsById[$ticketId] = $ticket;
            $wantedEntityIds[$entityId] = true;

            if ($categoryId > 0) {
                $wantedCategoryIds[$categoryId] = true;
            }
        }

        if ($ticketsById === []) {
            return $result;
        }

        /*
         * Load the complete entity parent map once.
         */
        $entityParents = [];
        $entityNames = [];

        foreach (
            $DB->request([
            'SELECT' => [
                'id',
                'name',
                'completename',
                'entities_id',
            ],
            'FROM' => 'glpi_entities',
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $entityId = (int) ($row['id'] ?? 0);

            $parentRaw = $row['entities_id'] ?? null;
            $parentId = $parentRaw === null
                ? null
                : (int) $parentRaw;

            $entityParents[$entityId] = $parentId;

            $completeName = trim((string) (
                $row['completename'] ?? ''
            ));

            $name = trim((string) (
                $row['name'] ?? ''
            ));

            $entityNames[$entityId] =
                $completeName !== ''
                    ? $completeName
                    : (
                        $name !== ''
                            ? $name
                            : 'Entity #' . $entityId
                    );
        }

        /*
         * Resolve an entity ancestry chain locally.
         */
        $entityChains = [];

        foreach (array_keys($wantedEntityIds) as $entityId) {
            $entityId = (int) $entityId;
            $chain = [];
            $seen = [];
            $current = $entityId;

            while (
                $current >= 0
                 && !isset($seen[$current])
            ) {
                $chain[] = $current;
                $seen[$current] = true;

                if (!array_key_exists($current, $entityParents)) {
                    break;
                }

                $parent = $entityParents[$current];

                if ($parent === null) {
                    break;
                }

                $current = (int) $parent;
            }

            $entityChains[$entityId] = $chain;
        }

        /*
         * Load support matrix for the selected Incidentologue group.
         */
        $supportRows = [];

        if ($supportAvailable && $groupId > 0) {
            foreach (
                $DB->request([
                'FROM' => $groupCategoryTable,
                'WHERE' => [
                    'groups_id' => $groupId,
                ],
                ]) as $row
            ) {
                if (!is_array($row)) {
                    continue;
                }

                $result['support_matrix_rows_total']++;

                $entityId =
                    (int) ($row['entities_id'] ?? 0);

                $categoryId =
                    (int) ($row['itilcategories_id'] ?? 0);

                if ($categoryId <= 0) {
                    continue;
                }

                $supportRows[$entityId][$categoryId] = [
                    'level' => trim((string) (
                        $row['level'] ?? ''
                    )),
                    'source_entity_id' => $entityId,
                ];
            }
        }

        /*
         * Load all category targets. The dataset is intentionally validated
         * before any target is exposed to Incidentologue.
         */
        $targetRows = [];

        if ($targetsAvailable) {
            foreach (
                $DB->request([
                'FROM' => $targetTable,
                ]) as $row
            ) {
                if (!is_array($row)) {
                    continue;
                }

                $result['category_target_rows_total']++;

                $entityId =
                    (int) ($row['entities_id'] ?? 0);

                $categoryId =
                    (int) ($row['itilcategories_id'] ?? 0);

                if ($categoryId <= 0) {
                    continue;
                }

                $fcr = (float) (
                    $row['target_fcr'] ?? 0
                );

                $kbUsage = (float) (
                    $row['target_kb_usage'] ?? 0
                );

                $kbQuality = (float) (
                    $row['target_kb_quality'] ?? 0
                );

                $isValid =
                    $fcr >= 0.0
                    && $fcr <= 1.0
                    && $kbUsage >= 0.0
                    && $kbUsage <= 1.0
                    && $kbQuality >= 0.0
                    && $kbQuality <= 5.0;

                $targetRows[$entityId][$categoryId] = [
                    'target_fcr' => $fcr,
                    'target_kb_usage' => $kbUsage,
                    'target_kb_quality' => $kbQuality,
                    'source_entity_id' => $entityId,
                    'valid' => $isValid,
                ];

                if (!$isValid) {
                    $result['invalid_target_rows'][] = [
                        'entity_id' => $entityId,
                        'entity_name' =>
                            $entityNames[$entityId]
                            ?? ('Entity #' . $entityId),
                        'category_id' => $categoryId,
                        'target_fcr' => $fcr,
                        'target_kb_usage' => $kbUsage,
                        'target_kb_quality' => $kbQuality,
                    ];
                }
            }
        }

        /*
         * Resolve inherited values ticket by ticket, entirely in memory.
         */
        foreach ($ticketsById as $ticketId => $ticket) {
            $entityId =
                (int) ($ticket['entities_id'] ?? 0);

            $categoryId =
                (int) ($ticket['itilcategories_id'] ?? 0);

            $chain =
                $entityChains[$entityId]
                ?? [$entityId];

            $supportLevel = null;
            $supportSourceEntityId = null;

            if (
                $supportAvailable
                 && $groupId > 0
                 && $categoryId > 0
            ) {
                foreach ($chain as $candidateEntityId) {
                    $support =
                        $supportRows[$candidateEntityId][$categoryId]
                        ?? null;

                    if (!is_array($support)) {
                        continue;
                    }

                    $level = trim((string) (
                        $support['level']
                    ));

                    if ($level === '') {
                        continue;
                    }

                    $supportLevel = $level;
                    $supportSourceEntityId =
                        (int) $candidateEntityId;

                    break;
                }
            }

            $targets = null;
            $targetSourceEntityId = null;
            $invalidOverrides = [];

            if ($targetsAvailable && $categoryId > 0) {
                foreach ($chain as $candidateEntityId) {
                    $candidate =
                        $targetRows[$candidateEntityId][$categoryId]
                        ?? null;

                    if (!is_array($candidate)) {
                        continue;
                    }

                    if (!$candidate['valid']) {
                        $invalidOverrides[] = [
                            'entity_id' =>
                                (int) $candidateEntityId,
                            'entity_name' =>
                                $entityNames[$candidateEntityId]
                                ?? ('Entity #' . $candidateEntityId),
                            'target_fcr' =>
                                (float) $candidate['target_fcr'],
                            'target_kb_usage' =>
                                (float) $candidate['target_kb_usage'],
                            'target_kb_quality' =>
                                (float) $candidate['target_kb_quality'],
                        ];

                        continue;
                    }

                    $targetSourceEntityId =
                        (int) $candidateEntityId;

                    $targets = [
                        'target_fcr_ratio' =>
                            (float) $candidate['target_fcr'],

                        'target_fcr_percent' =>
                            round(
                                (float) $candidate['target_fcr'] * 100,
                                1,
                            ),

                        'target_kb_usage_ratio' =>
                            (float) $candidate['target_kb_usage'],

                        'target_kb_usage_percent' =>
                            round(
                                (float) $candidate['target_kb_usage'] * 100,
                                1,
                            ),

                        'target_kb_quality' =>
                            (float) $candidate['target_kb_quality'],
                    ];

                    break;
                }
            }

            $result['tickets'][$ticketId] = [
                'ticket_id' => $ticketId,
                'ticket_entity_id' => $entityId,
                'ticket_entity_name' =>
                    $entityNames[$entityId]
                    ?? ('Entity #' . $entityId),
                'entity_inheritance_chain' =>
                    $chain,

                'category_id' => $categoryId,

                'support_level' => $supportLevel,
                'support_level_source_entity_id' =>
                    $supportSourceEntityId,
                'support_level_source_entity_name' =>
                    $supportSourceEntityId !== null
                        ? (
                            $entityNames[$supportSourceEntityId]
                            ?? ('Entity #' . $supportSourceEntityId)
                        )
                        : null,

                'targets' => $targets,
                'target_source_entity_id' =>
                    $targetSourceEntityId,
                'target_source_entity_name' =>
                    $targetSourceEntityId !== null
                        ? (
                            $entityNames[$targetSourceEntityId]
                            ?? ('Entity #' . $targetSourceEntityId)
                        )
                        : null,

                'invalid_target_overrides' =>
                    $invalidOverrides,
            ];
        }

        return $result;
    }
    /**
     * Parse a Group value generated by GLPI CommonDBTM::getNameID(forceid=true).
     *
     * Expected examples:
     * - Support N1 (101)
     * - Support N2 (102)
     *
     * The final numeric identifier is authoritative. The historical label is
     * retained because the current group name may have changed since the log
     * entry was written.
     *
     * @return array{id:int,name:string,historical_name:string,historical_value:string}|null
     */
    private function parseHistoricalGroup(?string $value): ?array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(.*)\s+\((\d+)\)$/u', $value, $matches) !== 1) {
            return null;
        }

        $groupId = (int) $matches[2];
        if ($groupId <= 0) {
            return null;
        }

        $historicalName = trim((string) $matches[1]);
        $currentName = $this->lookupName('glpi_groups', $groupId);

        $name = $currentName !== null && $currentName !== ''
            ? $currentName
            : ($historicalName !== '' ? $historicalName : 'Group #' . $groupId);

        return [
            'id' => $groupId,
            'name' => $name,
            'historical_name' => $historicalName,
            'historical_value' => $value,
        ];
    }




    /**
     * Replay technical-group history using an already loaded batch dataset.
     *
     * No SQL query is executed here.
     *
     * @param array<string,mixed> $ticket
     * @param list<array<string,mixed>> $groupLogs
     * @param list<array<string,mixed>> $solvedateLogs
     *
     * @return array{
     *   initial_assignment_group:array{id:int,name:string,historical_name:string,historical_value:string}|null,
     *   active_groups_at_resolution:list<array{id:int,name:string,historical_name:string,historical_value:string}>,
     *   history_event_count:int,
     *   unparsed_history_event_count:int,
     *   has_usable_history:bool,
     *   cutoff_source:string,
     *   resolution_history_boundary_id:int|null
     * }
     */
    private function replayTechnicalGroupHistoryFromDataset(
        array $ticket,
        array $groupLogs,
        array $solvedateLogs
    ): array {
        $ticketId = (int) ($ticket['id'] ?? 0);
        $solvedate = trim((string) ($ticket['solvedate'] ?? ''));
        $solveTimestamp = $this->timestamp($solvedate);

        $emptyResult = [
            'initial_assignment_group' => null,
            'active_groups_at_resolution' => [],
            'history_event_count' => 0,
            'unparsed_history_event_count' => 0,
            'has_usable_history' => false,
            'cutoff_source' => 'unavailable',
            'resolution_history_boundary_id' => null,
        ];

        if ($ticketId <= 0 || $solveTimestamp === null) {
            return $emptyResult;
        }

        $boundaryId = null;

        foreach ($solvedateLogs as $log) {
            if (!is_array($log)) {
                continue;
            }

            if ((int) ($log['items_id'] ?? 0) !== $ticketId) {
                continue;
            }

            if (trim((string) ($log['new_value'] ?? '')) !== $solvedate) {
                continue;
            }

            $logId = (int) ($log['id'] ?? 0);
            if ($logId > 0 && ($boundaryId === null || $logId > $boundaryId)) {
                $boundaryId = $logId;
            }
        }

        usort(
            $groupLogs,
            static function (array $a, array $b): int {
                $dateComparison = strcmp(
                    (string) ($a['date_mod'] ?? ''),
                    (string) ($b['date_mod'] ?? ''),
                );

                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
            },
        );

        $activeGroups = [];
        $initialAssignmentGroup = null;
        $historyEventCount = 0;
        $unparsedHistoryEventCount = 0;

        foreach ($groupLogs as $log) {
            if (!is_array($log)) {
                continue;
            }

            if ((int) ($log['items_id'] ?? 0) !== $ticketId) {
                continue;
            }

            $logTimestamp = $this->timestamp($log['date_mod'] ?? null);
            if ($logTimestamp === null) {
                continue;
            }

            if ($logTimestamp > $solveTimestamp) {
                break;
            }

            $logId = (int) ($log['id'] ?? 0);

            if (
                $boundaryId !== null
                 && $logTimestamp === $solveTimestamp
                 && $logId > $boundaryId
            ) {
                break;
            }

            $action = (int) ($log['linked_action'] ?? 0);

            if (!in_array($action, [15, 16], true)) {
                continue;
            }

            $rawGroup = $action === 15
                ? ($log['new_value'] ?? null)
                : ($log['old_value'] ?? null);

            $group = $this->parseHistoricalGroup(
                is_string($rawGroup) ? $rawGroup : null,
            );

            if ($group === null) {
                $unparsedHistoryEventCount++;
                continue;
            }

            $historyEventCount++;

            if ($action === 15) {
                if ($initialAssignmentGroup === null) {
                    $initialAssignmentGroup = $group;
                }

                $activeGroups[$group['id']] = $group;
                continue;
            }

            unset($activeGroups[$group['id']]);
        }

        return [
            'initial_assignment_group' => $initialAssignmentGroup,
            'active_groups_at_resolution' => array_values($activeGroups),
            'history_event_count' => $historyEventCount,
            'unparsed_history_event_count' => $unparsedHistoryEventCount,
            'has_usable_history' => $historyEventCount > 0,
            'cutoff_source' => $boundaryId !== null
                ? 'solvedate_history_boundary'
                : 'solvedate_timestamp',
            'resolution_history_boundary_id' => $boundaryId,
        ];
    }


    /**
    * @param array<string, mixed> $criteria
    *
    * @return list<array<string, mixed>>
    */
    private function filteredTickets(array $criteria): array
    {
        $rows = [];
        foreach ($this->allTickets() as $row) {
            if (!$this->matches($row, $criteria)) {
                continue;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function allTickets(): array
    {
        global $DB;
        $rows = [];
        foreach (
            $DB->request([
            'FROM' => 'glpi_tickets',
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['id DESC'],
            'LIMIT' => self::MAX_ROWS,
            ]) as $row
        ) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
    * @param array<string, mixed> $row
    * @param array<string, mixed> $criteria
    */
    private function matches(array $row, array $criteria): bool
    {
        $created = substr((string) ($row['date'] ?? $row['date_creation'] ?? ''), 0, 10);
        $from = trim((string) ($criteria['date_from'] ?? ''));
        $to = trim((string) ($criteria['date_to'] ?? ''));
        if ($from !== '' && $created !== '' && strcmp($created, $from) < 0) {
            return false;
        }
        if ($to !== '' && $created !== '' && strcmp($created, $to) > 0) {
            return false;
        }
        if (
            isset($criteria['entity_id']) && (int) $criteria['entity_id'] > 0
            && (int) ($row['entities_id'] ?? 0) !== (int) $criteria['entity_id']
        ) {
            return false;
        }
        if (
            isset($criteria['category_id']) && (int) $criteria['category_id'] > 0
            && (int) ($row['itilcategories_id'] ?? 0) !== (int) $criteria['category_id']
        ) {
            return false;
        }
        if (isset($criteria['type']) && trim((string) $criteria['type']) !== '') {
            $type = strtolower(trim((string) $criteria['type']));
            $wanted = in_array($type, ['incident', '1'], true)
                ? 1
                : (in_array($type, ['request', 'demande', '2'], true) ? 2 : 0);
            if ($wanted > 0 && (int) ($row['type'] ?? 0) !== $wanted) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function dateInCriteria(string $date, array $criteria): bool
    {
        $day = substr(trim($date), 0, 10);
        if ($day === '' || strlen($day) !== 10) {
            return false;
        }
        $from = trim((string) ($criteria['date_from'] ?? ''));
        $to = trim((string) ($criteria['date_to'] ?? ''));
        if ($from !== '' && strcmp($day, $from) < 0) {
            return false;
        }
        if ($to !== '' && strcmp($day, $to) > 0) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    private function period(array $criteria): array
    {
        return [
            'date_from' => (string) ($criteria['date_from'] ?? ''),
            'date_to' => (string) ($criteria['date_to'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    private function periodObservation(array $criteria): array
    {
        $from = trim((string) ($criteria['date_from'] ?? ''));
        $to = trim((string) ($criteria['date_to'] ?? ''));
        $today = new \DateTimeImmutable('today');

        try {
            $start = $from !== '' ? new \DateTimeImmutable($from . ' 00:00:00') : null;
            $end = $to !== '' ? new \DateTimeImmutable($to . ' 00:00:00') : null;
        } catch (\Throwable) {
            $start = null;
            $end = null;
        }

        if ($start === null || $end === null || $end < $start) {
            return [
                'as_of_date' => $today->format('Y-m-d'),
                'requested_days' => null,
                'observed_days' => null,
                'observed_through' => null,
                'is_partial' => false,
            ];
        }

        $requestedDays = (int) $start->diff($end)->format('%a') + 1;
        $observedEnd = $end < $today ? $end : $today;
        $observedDays = $observedEnd >= $start ? (int) $start->diff($observedEnd)->format('%a') + 1 : 0;
        $isPartial = $end >= $today;

        return [
            'as_of_date' => $today->format('Y-m-d'),
            'requested_days' => $requestedDays,
            'observed_days' => $observedDays,
            'observed_through' => $observedDays > 0 ? $observedEnd->format('Y-m-d') : null,
            'is_partial' => $isPartial,
        ];
    }

    /**
    * @param array<string, mixed> $summary
    *
    * @return array<string, float|null>
    */
    private function dailyRates(array $summary): array
    {
        $observation = is_array($summary['period_observation'] ?? null) ? $summary['period_observation'] : [];
        $days = (int) ($observation['observed_days'] ?? 0);
        if ($days <= 0) {
            return [
                'opened_per_observed_day' => null,
                'incidents_per_observed_day' => null,
                'requests_per_observed_day' => null,
                'resolved_or_closed_per_observed_day' => null,
            ];
        }

        return [
            'opened_per_observed_day' => round(((int) ($summary['total'] ?? 0)) / $days, 2),
            'incidents_per_observed_day' => round(((int) ($summary['incidents'] ?? 0)) / $days, 2),
            'requests_per_observed_day' => round(((int) ($summary['requests'] ?? 0)) / $days, 2),
            'resolved_or_closed_per_observed_day' => round(((int) ($summary['resolved_or_closed'] ?? 0)) / $days, 2),
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, array<string, int|string>>
     */
    private function emptySeries(array $criteria, string $bucket): array
    {
        $from = trim((string) ($criteria['date_from'] ?? ''));
        $to = trim((string) ($criteria['date_to'] ?? ''));
        if ($from === '' || $to === '') {
            return [];
        }
        try {
            $start = new \DateTimeImmutable($from . ' 00:00:00');
            $end = new \DateTimeImmutable($to . ' 00:00:00');
        } catch (\Throwable) {
            return [];
        }
        if ($end < $start) {
            return [];
        }

        $series = [];
        if ($bucket === 'day') {
            for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
                $key = $cursor->format('Y-m-d');
                $series[$key] = $this->newTrendBucket($key);
            }
            return $series;
        }

        if ($bucket === 'week') {
            $cursor = $start->modify('monday this week');
            $last = $end->modify('monday this week');
            while ($cursor <= $last) {
                $key = $cursor->format('o-\WW');
                $series[$key] = $this->newTrendBucket($key);
                $cursor = $cursor->modify('+1 week');
            }
            return $series;
        }

        $cursor = $start->modify('first day of this month');
        $last = $end->modify('first day of this month');
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m');
            $series[$key] = $this->newTrendBucket($key);
            $cursor = $cursor->modify('+1 month');
        }
        return $series;
    }

    /** @return array{period:string,opened:int,resolved:int,incidents:int,requests:int} */
    private function newTrendBucket(string $key): array
    {
        return ['period' => $key, 'opened' => 0, 'resolved' => 0, 'incidents' => 0, 'requests' => 0];
    }

    private function bucketKey(string $date, string $bucket): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }
        return match ($bucket) {
            'month' => date('Y-m', $ts),
            'week' => date('o-\WW', $ts),
            default => date('Y-m-d', $ts),
        };
    }

    /** @param list<int> $values */
    private function average(array $values): ?int
    {
        return $values === [] ? null : (int) round(array_sum($values) / count($values));
    }

    private function coverage(int $sample, int $total): ?float
    {
        return $total > 0 ? round(($sample / $total) * 100, 2) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function ownershipDurationSeconds(array $row): ?int
    {
        $created = $this->timestamp($row['date'] ?? $row['date_creation'] ?? null);
        $ownedAt = $this->timestamp($row['takeintoaccountdate'] ?? null);
        $stat = isset($row['takeintoaccount_delay_stat']) && is_numeric($row['takeintoaccount_delay_stat'])
            ? (int) $row['takeintoaccount_delay_stat']
            : null;

        if ($stat !== null && $stat > 0) {
            return $stat;
        }
        if ($created !== null && $ownedAt !== null && $ownedAt >= $created) {
            return $ownedAt - $created;
        }
        if ($stat === 0) {
            return 0;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function durationSeconds(array $row, string $statField, ?string $fromField, ?string $toField): ?int
    {
        return $this->durationWithSource(
            $row,
            $statField,
            $fromField,
            $toField,
        )['seconds'];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{seconds: int|null, source: string|null}
     */
    private function durationWithSource(
        array $row,
        string $statField,
        ?string $fromField,
        ?string $toField
    ): array {
        $stat = isset($row[$statField])
            && is_numeric($row[$statField])
            && (int) $row[$statField] >= 0
                ? (int) $row[$statField]
                : null;

        $fallback = null;

        if ($fromField !== null && $toField !== null) {
            $from = $this->timestamp($row[$fromField] ?? null);
            $to = $this->timestamp($row[$toField] ?? null);

            if ($from !== null && $to !== null && $to >= $from) {
                $fallback = $to - $from;
            }
        }

        if ($stat !== null && $stat > 0) {
            return ['seconds' => $stat, 'source' => 'glpi_stat'];
        }

        if ($fallback !== null && $fallback > 0) {
            return ['seconds' => $fallback, 'source' => 'calendar_fallback'];
        }

        if ($stat === 0 && $fallback === 0) {
            return ['seconds' => 0, 'source' => 'glpi_stat'];
        }

        if ($stat === 0 && $fallback === null) {
            return ['seconds' => null, 'source' => null];
        }

        if ($fallback !== null) {
            return ['seconds' => $fallback, 'source' => 'calendar_fallback'];
        }

        return ['seconds' => null, 'source' => null];
    }

    /** @return array<string,int|float|null> */
    private function newSlaMetric(): array
    {
        return [
            'deadline_available' => 0,
            'evaluable' => 0,
            'met' => 0,
            'breached' => 0,
            'open_with_deadline' => 0,
            'currently_breached' => 0,
            'currently_within_deadline' => 0,
            'not_evaluable' => 0,
            'compliance_rate' => null,
        ];
    }

    /**
     * @param array<string,int|float|null> $metric
     * @param list<array<string,mixed>> $breaches
     * @param array<string,mixed> $row
     *
     * @return list<array<string,mixed>>
     */
    private function evaluateSlaMetric(
        array &$metric,
        array $breaches,
        array $row,
        string $metricName,
        ?int $deadline,
        ?int $actual,
        int $now
    ): array {
        if ($deadline === null) {
            return $breaches;
        }

        $metric['deadline_available'] = (int) $metric['deadline_available'] + 1;

        if ($actual !== null) {
            $metric['evaluable'] = (int) $metric['evaluable'] + 1;

            if ($actual <= $deadline) {
                $metric['met'] = (int) $metric['met'] + 1;

                return $breaches;
            }

            $metric['breached'] = (int) $metric['breached'] + 1;
            $breaches[] = $this->slaBreachRow(
                $row,
                $metricName,
                $deadline,
                $actual,
                'historical_breach',
            );

            return $breaches;
        }

        $metric['open_with_deadline'] = (int) $metric['open_with_deadline'] + 1;

        if ($now > $deadline) {
            $metric['currently_breached'] = (int) $metric['currently_breached'] + 1;
            $breaches[] = $this->slaBreachRow(
                $row,
                $metricName,
                $deadline,
                $now,
                'currently_breached',
            );
        } else {
            $metric['currently_within_deadline'] = (int) $metric['currently_within_deadline'] + 1;
        }

        return $breaches;
    }

    /**
    * @param array<string, int|float|null> $metric
    *
    * @return array<string, int|float|null>
    */
    private function finishSlaMetric(array $metric, int $ticketsScanned): array
    {
        $metric['not_evaluable'] = max(0, $ticketsScanned - (int) $metric['deadline_available']);
        $metric['compliance_rate'] = (int) $metric['evaluable'] > 0
            ? round(((int) $metric['met'] / (int) $metric['evaluable']) * 100, 2)
            : null;
        return $metric;
    }

    /**
    * @param array<string, mixed> $row
    *
    * @return array<string, mixed>
    */
    private function slaBreachRow(array $row, string $metric, int $deadline, int $actual, string $state): array
    {
        $ticketId = (int) ($row['id'] ?? 0);
        $seconds = max(0, $actual - $deadline);
        return [
            'ticket_id' => $ticketId,
            'title' => trim((string) ($row['name'] ?? ('Ticket #' . $ticketId))),
            'status' => $this->ticketStatusLabel((int) ($row['status'] ?? 0)),
            'entity' => $this->lookupName('glpi_entities', (int) ($row['entities_id'] ?? 0)),
            'category' => $this->lookupName('glpi_itilcategories', (int) ($row['itilcategories_id'] ?? 0)),
            'assigned_groups' => $this->assignedGroupNames($ticketId),
            'metric' => $metric,
            'state' => $state,
            'deadline' => date('Y-m-d H:i:s', $deadline),
            'actual' => date('Y-m-d H:i:s', $actual),
            'breach_duration_seconds' => $seconds,
            'breach_duration_human' => $this->humanDuration($seconds),
        ];
    }

    /** @return list<string> */
    private function assignedGroupNames(int $ticketId): array
    {
        global $DB;
        if ($ticketId <= 0 || !$DB->tableExists('glpi_groups_tickets')) {
            return [];
        }
        $names = [];
        foreach (
            $DB->request([
            'FROM' => 'glpi_groups_tickets',
            'WHERE' => ['tickets_id' => $ticketId, 'type' => 2]
            ]) as $row
        ) {
            $groupId = (int) ($row['groups_id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }
            $name = $this->lookupName('glpi_groups', $groupId) ?? ('Group #' . $groupId);
            $names[$groupId] = $name;
        }
        return array_values($names);
    }

    private function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '' || !str_contains($value, '-')) {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }

    private function humanDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;
        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' d';
        }
        if ($hours > 0) {
            $parts[] = $hours . ' h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' min';
        }
        if ($seconds > 0 || $parts === []) {
            $parts[] = $seconds . ' s';
        }
        return implode(' ', $parts);
    }

    /**
     * @return array{
     *     category_id:int,
     *     category_name:string,
     *     category_path:string,
     *     parent_category_id:int|null,
     *     parent_category_name:string|null
     * }
     */
    private function categoryMetadata(int $id): array
    {
        global $DB;

        static $cache = [];

        if (isset($cache[$id])) {
            return $cache[$id];
        }

        if ($id <= 0) {
            return $cache[$id] = [
                'category_id' => 0,
                'category_name' => 'Uncategorized',
                'category_path' => 'Uncategorized',
                'parent_category_id' => null,
                'parent_category_name' => null,
            ];
        }

        $fallbackName = 'Category #' . $id;

        if (!$DB->tableExists('glpi_itilcategories')) {
            return $cache[$id] = [
                'category_id' => $id,
                'category_name' => $fallbackName,
                'category_path' => $fallbackName,
                'parent_category_id' => null,
                'parent_category_name' => null,
            ];
        }

        foreach (
            $DB->request([
            'SELECT' => ['name', 'completename', 'itilcategories_id'],
            'FROM' => 'glpi_itilcategories',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
            ]) as $row
        ) {
            $name = trim((string) ($row['name'] ?? ''));
            $path = trim((string) ($row['completename'] ?? ''));
            $parentId = (int) ($row['itilcategories_id'] ?? 0);

            $name = $name !== '' ? $name : $fallbackName;
            $path = $path !== '' ? $path : $name;

            return $cache[$id] = [
                'category_id' => $id,
                'category_name' => $name,
                'category_path' => $path,
                'parent_category_id' => $parentId,
                'parent_category_name' => $parentId > 0
                    ? $this->lookupName('glpi_itilcategories', $parentId)
                    : null,
            ];
        }

        return $cache[$id] = [
            'category_id' => $id,
            'category_name' => $fallbackName,
            'category_path' => $fallbackName,
            'parent_category_id' => null,
            'parent_category_name' => null,
        ];
    }
    private function lookupName(string $table, int $id): ?string
    {
        global $DB;
        if ($id <= 0 || !$DB->tableExists($table)) {
            return null;
        }
        foreach (
            $DB->request([
            'SELECT' => ['name'],
            'FROM' => $table,
            'WHERE' => ['id' => $id],
            'LIMIT' => 1
            ]) as $row
        ) {
            $name = trim((string) ($row['name'] ?? ''));
            return $name !== '' ? $name : null;
        }
        return null;
    }

    private function userName(int $id): string
    {
        global $DB;
        if ($id <= 0 || !$DB->tableExists('glpi_users')) {
            return 'User #' . $id;
        }
        foreach (
            $DB->request([
            'SELECT' => ['name', 'firstname', 'realname'],
            'FROM' => 'glpi_users',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1
            ]) as $row
        ) {
            $display = trim(implode(' ', array_filter([
                (string) ($row['firstname'] ?? ''),
                (string) ($row['realname'] ?? '')
            ])));
            return $display !== '' ? $display : (string) ($row['name'] ?? ('User #' . $id));
        }
        return 'User #' . $id;
    }

    /** @return array<string,mixed> */
    private function newTechnicianStat(int $userId): array
    {
        return [
            'user_id' => $userId,
            'technician' => $this->userName($userId),
            'assigned_ticket_ids' => [],
            'intervened_ticket_ids' => [],
            'active_backlog_ticket_ids' => [],
            'resolved_or_closed_ticket_ids' => [],
            'tasks' => 0,
            'task_work_seconds' => 0,
        ];
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\s]+/', trim($value)) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                $ids[(int) $candidate] = true;
            }
        }
        return array_map('intval', array_keys($ids));
    }

    private function ticketStatusLabel(int $status): ?string
    {
        return match ($status) {
            1 => 'New',
            2 => 'Processing (assigned)',
            3 => 'Processing (planned)',
            4 => 'Pending',
            5 => 'Solved',
            6 => 'Closed',
            default => $status > 0 ? (string) $status : null,
        };
    }
}
