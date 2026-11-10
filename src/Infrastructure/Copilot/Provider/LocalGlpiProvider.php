<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Provider;

use GlpiPlugin\Uxconnector\Domain\Copilot\Model\CanonicalTicket;
use GlpiPlugin\Uxconnector\Domain\Copilot\Port\ItsmDataProviderInterface;

/**
 * Read-only provider for the GLPI instance hosting UXConnector.
 *
 * Unlike GlpiHlApiProvider this adapter does not loop back through HTTP.
 * It is therefore the preferred provider when Copilot is connected to the
 * very same GLPI instance: no second OAuth session, no self-HTTP request and
 * no relaxation of UXConnector's SSRF/TLS protections are required.
 */
final class LocalGlpiProvider implements ItsmDataProviderInterface
{
    private const MAX_SCAN_ROWS = 5000;

    public function capabilities(): array
    {
        return [
            'provider' => 'local-glpi',
            'read_only' => true,
            'tools' => ['searchTickets', 'getTicketContext', 'analyzeTicketVolume', 'analyzeTicketTrends',
                'analyzeCategories', 'analyzeSlaPerformance', 'analyzeSlaBreaches', 'analyzeGroupPerformance',
                'analyzeTechnicianWorkload', 'comparePeriods', 'analyzeIncidentologyGlobal',
                'analyzeIncidentologyTypologies', 'analyzeSemanticTicketClassification',
                'prepareSemanticTicketClassification', 'finalizeSemanticTicketClassification',
                'listIncidentologyGroups'],
            'data_source' => 'GLPI local domain/database',
        ];
    }

    public function searchTickets(array $criteria): array
    {
        global $DB;

        $query = mb_strtolower(trim((string) ($criteria['query'] ?? '')));
        $statusFilter = $this->normalizeList($criteria['status'] ?? []);
        $dateFrom = trim((string) ($criteria['date_from'] ?? ''));
        $dateTo = trim((string) ($criteria['date_to'] ?? ''));
        $limit = max(1, min(100, (int) ($criteria['limit'] ?? 25)));

        $iterator = $DB->request([
            'FROM' => 'glpi_tickets',
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['id DESC'],
            'LIMIT' => self::MAX_SCAN_ROWS,
        ]);

        $tickets = [];
        foreach ($iterator as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ticket = $this->normalizeTicketRow($row, false);

            if ($query !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $ticket->title,
                    $ticket->description,
                    (string) $ticket->category,
                    (string) $ticket->reference,
                ]));
                if (!str_contains($haystack, $query)) {
                    continue;
                }
            }

            if ($statusFilter !== []) {
                $statusCandidates = [
                    mb_strtolower((string) $ticket->status),
                    mb_strtolower((string) ($row['status'] ?? '')),
                ];
                if (array_intersect($statusFilter, $statusCandidates) === []) {
                    continue;
                }
            }

            $created = $ticket->createdAt !== null ? substr($ticket->createdAt, 0, 10) : '';
            if ($dateFrom !== '' && $created !== '' && strcmp($created, $dateFrom) < 0) {
                continue;
            }
            if ($dateTo !== '' && $created !== '' && strcmp($created, $dateTo) > 0) {
                continue;
            }

            $people = $this->ticketUsers((int) ($row['id'] ?? 0));
            $groups = $this->ticketGroups((int) ($row['id'] ?? 0));
            $assets = $this->ticketAssets((int) ($row['id'] ?? 0));
            $tickets[] = $this->normalizeTicketRow($row, true, $people, $groups, $assets);
            if (count($tickets) >= $limit) {
                break;
            }
        }

        return $tickets;
    }

    public function getTicketContext(int $ticketId): array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM' => 'glpi_tickets',
            'WHERE' => ['id' => $ticketId, 'is_deleted' => 0],
            'LIMIT' => 1,
        ]);

        $row = null;
        foreach ($iterator as $candidate) {
            $row = is_array($candidate) ? $candidate : null;
            break;
        }
        if ($row === null) {
            throw new \RuntimeException(sprintf('Ticket %d does not exist or is deleted.', $ticketId));
        }

        $people = $this->ticketUsers($ticketId);
        $groups = $this->ticketGroups($ticketId);
        $assets = $this->ticketAssets($ticketId);

        $tasks = $this->enrichRows($this->rowsIfTableExists(
            'glpi_tickettasks',
            ['tickets_id' => $ticketId],
            ['id DESC']
        ), 'task');
        $followups = $this->enrichRows($this->rowsIfTableExists('glpi_itilfollowups', ['itemtype' => 'Ticket',
                    'items_id' => $ticketId], ['id DESC']), 'followup');
        $solutions = $this->enrichRows($this->rowsIfTableExists('glpi_itilsolutions', ['itemtype' => 'Ticket',
                    'items_id' => $ticketId], ['id DESC']), 'solution');
        $validations = $this->enrichRows($this->rowsIfTableExists(
            'glpi_ticketvalidations',
            ['tickets_id' => $ticketId],
            ['id DESC']
        ), 'validation');
        $ticket = $this->normalizeTicketRow($row, true, $people, $groups, $assets)->toArray();
        $ticket['assigned_groups'] = array_values(array_map(
            static fn(array $group): array => ['id' => (int) ($group['id'] ?? 0), 'name' => (string) (
                    $group['name'] ?? '')],
            array_filter($groups, static fn(array $group): bool => ($group['role'] ?? '') === 'assigned'),
        ));
        $ticket['time_to_own_seconds'] = $this->ownershipDurationSeconds($row);
        $ticket['time_to_own_human'] = $this->humanDuration($ticket['time_to_own_seconds']);
        $ticket['time_to_resolve_seconds'] = $this->resolutionDurationSeconds($row);
        $ticket['time_to_resolve_human'] = $this->humanDuration($ticket['time_to_resolve_seconds']);
        $taskWork = array_sum(array_map(
            static fn(array $task): int => max(0, (int) ($task['actiontime'] ?? 0)),
            $tasks
        ));
        $ticket['task_work_seconds'] = $taskWork;
        $ticket['task_work_human'] = $this->humanDuration($taskWork);
        usort($solutions, static function (array $a, array $b): int {
            $aDate = (string) ($a['date_creation'] ?? $a['date_mod'] ?? '');
            $bDate = (string) ($b['date_creation'] ?? $b['date_mod'] ?? '');
            return strcmp($bDate, $aDate);
        });
        $finalSolution = $solutions[0] ?? null;

        return [
            'ticket' => $ticket,
            'team_members' => ['users' => $people, 'groups' => $groups],
            'timeline' => $this->canonicalTimeline($followups, $tasks, $solutions, $validations),
            'followups' => $followups,
            'solutions' => $solutions,
            'final_solution' => $finalSolution,
            'solution_history' => $solutions,
            'tasks' => $tasks,
            'validations' => $validations,
            'knowledge_articles' => $this->knowledgeArticles($ticketId),
            'assets' => $assets,
        ];
    }

    /**
    * @param array<string, mixed>       $row
    * @param list<array<string, mixed>> $people
    * @param list<array<string, mixed>> $groups
    * @param list<array<string, mixed>> $assets
    */
    private function normalizeTicketRow(
        array $row,
        bool $enrich,
        array $people = [],
        array $groups = [],
        array $assets = []
    ): CanonicalTicket {
        $id = (int) ($row['id'] ?? 0);
        $requester = null;
        $assignedUsers = [];
        foreach ($people as $person) {
            if (($person['role'] ?? '') === 'requester' && $requester === null) {
                $requester = (string) ($person['name'] ?? '');
            }
            if (($person['role'] ?? '') === 'assigned') {
                $name = trim((string) ($person['name'] ?? ''));

                if ($name !== '') {
                    $assignedUsers[] = $name;
                }
            }
        }

        $assignedGroup = null;
        foreach ($groups as $group) {
            if (($group['role'] ?? '') === 'assigned') {
                $assignedGroup = (string) ($group['name'] ?? '');
                break;
            }
        }

        return new CanonicalTicket(
            id: $id,
            reference: (string) ($row['name'] ?? ('Ticket #' . $id)),
            title: $this->cleanText((string) ($row['name'] ?? '')),
            description: $this->cleanText((string) ($row['content'] ?? '')),
            type: $this->ticketTypeLabel((int) ($row['type'] ?? 0)),
            status: $this->ticketStatusLabel((int) ($row['status'] ?? 0)),
            priority: $this->levelLabel((int) ($row['priority'] ?? 0)),
            urgency: $this->levelLabel((int) ($row['urgency'] ?? 0)),
            impact: $this->levelLabel((int) ($row['impact'] ?? 0)),
            category: $this->lookupName('glpi_itilcategories', (int) ($row['itilcategories_id'] ?? 0)),
            entity: $this->lookupName('glpi_entities', (int) ($row['entities_id'] ?? 0)),
            requester: $enrich ? $requester : null,
            assignedGroup: $enrich ? $assignedGroup : null,
            assignedUsers: $enrich ? $assignedUsers : [],
            assets: $enrich ? $assets : [],
            createdAt: $this->nullableString($row['date'] ?? $row['date_creation'] ?? null),
            resolvedAt: $this->nullableString($row['solvedate'] ?? null),
            closedAt: $this->nullableString($row['closedate'] ?? null),
            timeToOwn: $this->nullableString($row['time_to_own'] ?? $row['takeintoaccount_delay_stat'] ?? null),
            timeToResolve: $this->nullableString($row['time_to_resolve'] ?? $row['solve_delay_stat'] ?? null),
            raw: [],
        );
    }

    /** @return list<array<string,mixed>> */
    private function ticketUsers(int $ticketId): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_tickets_users')) {
            return [];
        }
        $rows = [];
        $iterator = $DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $ticketId]]);
        foreach ($iterator as $row) {
            $userId = (int) ($row['users_id'] ?? 0);
            $rows[] = [
                'id' => $userId,
                'name' => $this->userName($userId),
                'role' => match ((int) ($row['type'] ?? 0)) {
                    1 => 'requester', 2 => 'assigned', 3 => 'observer', default => 'other'
                },
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function ticketGroups(int $ticketId): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_groups_tickets')) {
            return [];
        }
        $rows = [];
        $iterator = $DB->request(['FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $ticketId]]);
        foreach ($iterator as $row) {
            $groupId = (int) ($row['groups_id'] ?? 0);
            $rows[] = [
                'id' => $groupId,
                'name' => (string) ($this->lookupName('glpi_groups', $groupId) ?? ('Group #' . $groupId)),
                'role' => match ((int) ($row['type'] ?? 0)) {
                    1 => 'requester', 2 => 'assigned', 3 => 'observer', default => 'other'
                },
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function ticketAssets(int $ticketId): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_items_tickets')) {
            return [];
        }
        $rows = [];
        $iterator = $DB->request(['FROM' => 'glpi_items_tickets', 'WHERE' => ['tickets_id' => $ticketId]]);
        foreach ($iterator as $row) {
            $rows[] = [
                'itemtype' => (string) ($row['itemtype'] ?? ''),
                'id' => (int) ($row['items_id'] ?? 0),
            ];
        }
        return $rows;
    }



    /** @return list<array<string,mixed>> */
    private function knowledgeArticles(int $ticketId): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_items_knowbaseitems')) {
            return [];
        }
        $rows = [];
        $iterator = $DB->request([
            'FROM' => 'glpi_items_knowbaseitems',
            'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $ticketId],
        ]);
        foreach ($iterator as $row) {
            $kbId = (int) ($row['knowbaseitems_id'] ?? 0);
            $rows[] = [
                'id' => $kbId,
                'name' => (string) ($this->lookupName('glpi_knowbaseitems', $kbId) ?? ('KB #' . $kbId)),
            ];
        }
        return $rows;
    }

    /**
    * @param array<string, mixed> $where
    * @param list<string>         $order
    *
    * @return list<array<string, mixed>>
    */
    private function rowsIfTableExists(string $table, array $where, array $order = []): array
    {
        global $DB;
        if (!$DB->tableExists($table)) {
            return [];
        }
        $request = ['FROM' => $table, 'WHERE' => $where];
        if ($order !== []) {
            $request['ORDER'] = $order;
        }
        $rows = [];
        foreach ($DB->request($request) as $row) {
            if (is_array($row)) {
                $rows[] = $this->sanitizeTimelineRow($row);
            }
        }
        return $rows;
    }

    /**
    * @param array<string, mixed> $row
    *
    * @return array<string, mixed>
    */
    private function sanitizeTimelineRow(array $row): array
    {
        $allowed = ['id', 'date', 'date_creation', 'date_mod', 'content', 'users_id', 'users_id_tech',
            'groups_id_tech', 'actiontime', 'state', 'status', 'solutiontypes_id', 'is_private'];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $row)) {
                $out[$key] = is_string($row[$key]) && $key === 'content' ? $this->cleanText($row[$key]) : $row[$key];
            }
        }
        return $out;
    }

    /**
    * @param list<array<string, mixed>> $rows
    *
    * @return list<array<string, mixed>>
    */
    private function enrichRows(array $rows, string $kind): array
    {
        foreach ($rows as &$row) {
            $actorId = (int) ($row['users_id_tech'] ?? $row['users_id'] ?? 0);
            if ($actorId > 0) {
                $row['actor'] = ['id' => $actorId, 'name' => $this->userName($actorId)];
            } else {
                $row['actor'] = null;
            }
            $groupId = (int) ($row['groups_id_tech'] ?? 0);
            $row['technical_group'] = $groupId > 0 ? ['id' => $groupId, 'name' => $this->lookupName(
                'glpi_groups',
                $groupId
            ) ?? ('Group #' . $groupId)] : null;
            if ($kind === 'task') {
                $seconds = max(0, (int) ($row['actiontime'] ?? 0));
                $row['work_duration_seconds'] = $seconds;
                $row['work_duration_human'] = $this->humanDuration($seconds);
            }
        }
        unset($row);
        return $rows;
    }

    /**
    * @param list<array<string, mixed>> $followups
    * @param list<array<string, mixed>> $tasks
    * @param list<array<string, mixed>> $solutions
    * @param list<array<string, mixed>> $validations
    *
    * @return list<array<string, mixed>>
    */
    private function canonicalTimeline(array $followups, array $tasks, array $solutions, array $validations): array
    {
        $events = [];
        foreach (
            ['followup' => $followups, 'task' => $tasks, 'solution' => $solutions,
                'validation' => $validations] as $type => $rows
        ) {
            foreach ($rows as $row) {
                $event = [
                    'type' => $type,
                    'id' => (int) ($row['id'] ?? 0),
                    'occurred_at' => (string) ($row['date'] ?? $row['date_creation'] ?? $row['date_mod'] ?? ''),
                    'actor' => $row['actor'] ?? null,
                    'content' => (string) ($row['content'] ?? ''),
                ];
                if ($type === 'task') {
                    $event['work_duration_seconds'] = (int) ($row['work_duration_seconds'] ?? 0);
                    $event['work_duration_human'] = (string) ($row['work_duration_human'] ?? '0 s');
                }
                if (isset($row['status'])) {
                    $event['status'] = $row['status'];
                }
                if (isset($row['state'])) {
                    $event['state'] = $row['state'];
                }
                $events[] = $event;
            }
        }
        usort($events, static fn(array $a, array $b): int => strcmp(
            (string) $a['occurred_at'],
            (string) $b['occurred_at']
        ));
        return $events;
    }

    private function numericDuration(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    /** @param array<string,mixed> $row */
    private function ownershipDurationSeconds(array $row): ?int
    {
        $stat = $this->numericDuration($row['takeintoaccount_delay_stat'] ?? null);
        if ($stat !== null && $stat > 0) {
            return $stat;
        }
        $created = $this->dateTimestamp($row['date'] ?? $row['date_creation'] ?? null);
        $ownedAt = $this->dateTimestamp($row['takeintoaccountdate'] ?? null);
        if ($created !== null && $ownedAt !== null && $ownedAt >= $created) {
            return $ownedAt - $created;
        }
        return $stat;
    }

    /** @param array<string,mixed> $row */
    private function resolutionDurationSeconds(array $row): ?int
    {
        $stat = $this->numericDuration($row['solve_delay_stat'] ?? null);
        if ($stat !== null && $stat > 0) {
            return $stat;
        }
        $created = $this->dateTimestamp($row['date'] ?? $row['date_creation'] ?? null);
        $solvedAt = $this->dateTimestamp($row['solvedate'] ?? $row['closedate'] ?? null);
        if ($created !== null && $solvedAt !== null && $solvedAt >= $created) {
            $fallback = $solvedAt - $created;
            if ($fallback > 0 || $stat === 0) {
                return $fallback;
            }
        }
        return $stat;
    }

    private function dateTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '' || !str_contains($value, '-')) {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
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

    private function lookupName(string $table, int $id): ?string
    {
        global $DB;
        if ($id <= 0 || !$DB->tableExists($table)) {
            return null;
        }
        foreach (
            $DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => $id],
                    'LIMIT' => 1]) as $row
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
            $DB->request(['SELECT' => ['name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => [
                        'id' => $id], 'LIMIT' => 1]) as $row
        ) {
            $display = trim(implode(' ', array_filter([(string) ($row['firstname'] ?? ''), (string) (
                            $row['realname'] ?? '')])));
            return $display !== '' ? $display : (string) ($row['name'] ?? ('User #' . $id));
        }
        return 'User #' . $id;
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function ticketTypeLabel(int $type): ?string
    {
        return match ($type) {
            1 => 'Incident', 2 => 'Request', default => $type > 0 ? (string) $type : null
        };
    }

    private function ticketStatusLabel(int $status): ?string
    {
        return match ($status) {
            1 => 'New', 2 => 'Processing (assigned)', 3 => 'Processing (planned)', 4 => 'Pending', 5 => 'Solved',
                6 => 'Closed',
            default => $status > 0 ? (string) $status : null,
        };
    }

    private function levelLabel(int $level): ?string
    {
        return match ($level) {
            1 => 'Very low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very high', 6 => 'Major',
            default => $level > 0 ? (string) $level : null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' || $value === '0000-00-00 00:00:00' ? null : $value;
    }

    /** @return list<string> */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [mb_strtolower(trim($value))];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? mb_strtolower(trim((string) $item)) : '',
            $value,
        )));
    }
}
