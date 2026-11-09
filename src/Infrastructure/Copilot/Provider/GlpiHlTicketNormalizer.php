<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Provider;

use GlpiPlugin\Uxconnector\Domain\Copilot\Model\CanonicalTicket;

final class GlpiHlTicketNormalizer
{
    /** @param array<string,mixed> $row */
    public function normalize(array $row): CanonicalTicket
    {
        $id = (int) ($row['id'] ?? $row['ID'] ?? 0);
        $title = $this->scalar($row, ['name', 'title', 'subject']);
        return new CanonicalTicket(
            id: $id,
            reference: $this->scalar($row, ['reference', 'ref', 'ticket_number']) ?: (string) $id,
            title: $title,
            description: $this->scalar($row, ['content', 'description', 'body']),
            type: $this->label($row['type'] ?? null),
            status: $this->label($row['status'] ?? null),
            priority: $this->label($row['priority'] ?? null),
            urgency: $this->label($row['urgency'] ?? null),
            impact: $this->label($row['impact'] ?? null),
            category: $this->label($row['itilcategory'] ?? $row['category'] ?? null),
            entity: $this->label($row['entity'] ?? null),
            requester: $this->person($row['requester'] ?? $row['user'] ?? null),
            assignedGroup: $this->label($row['group'] ?? $row['group_tech'] ?? $row['assigned_group'] ?? null),
            assignedUsers: $this->people($row['users'] ?? $row['technicians'] ?? $row['assigned_users'] ?? []),
            assets: is_array($row['assets'] ?? null) ? array_values($row['assets']) : [],
            createdAt: $this->date($row, ['date_creation', 'created_at', 'date']),
            resolvedAt: $this->date($row, ['solvedate', 'resolved_at']),
            closedAt: $this->date($row, ['closedate', 'closed_at']),
            timeToOwn: $this->scalar($row, ['time_to_own', 'tto']),
            timeToResolve: $this->scalar($row, ['time_to_resolve', 'ttr']),
            raw: $row,
        );
    }

    /**
    * @param array<string, mixed> $row
    * @param list<string>         $keys
    */
    private function scalar(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        return '';
    }

    private function label(mixed $value): ?string
    {
        if (is_scalar($value)) {
            $text = trim((string) $value);
            return $text === '' ? null : $text;
        }
        if (is_array($value)) {
            foreach (['name', 'label', 'value', 'completename'] as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    return trim((string) $value[$key]) ?: null;
                }
            }
        }
        return null;
    }

    private function person(mixed $value): ?string
    {
        if (is_array($value)) {
            $first = trim((string) ($value['firstname'] ?? ''));
            $last = trim((string) ($value['realname'] ?? $value['lastname'] ?? ''));
            $full = trim($first . ' ' . $last);
            if ($full !== '') {
                return $full;
            }
        }
        return $this->label($value);
    }

    /** @return list<string> */
    private function people(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $person) {
            $name = $this->person($person);
            if ($name !== null) {
                $out[] = $name;
            }
        }
        return array_values(array_unique($out));
    }

    /**
    * @param array<string, mixed> $row
    * @param list<string>         $keys
    */
    private function date(array $row, array $keys): ?string
    {
        $value = $this->scalar($row, $keys);
        return $value === '' ? null : $value;
    }
}
