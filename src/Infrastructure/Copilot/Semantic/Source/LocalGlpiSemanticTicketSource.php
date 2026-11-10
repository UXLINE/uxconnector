<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\Source;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketInput;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\DTO\SemanticTicketSourceResult;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticTicketSourceInterface;

/**
 * Reads ticket text directly from the GLPI instance hosting UXConnector.
 *
 * The filtering semantics intentionally match
 * LocalGlpiAnalyticsProvider::filteredTickets()/matches() rather than creating
 * a second interpretation of date/type/entity/category scope.
 */
final class LocalGlpiSemanticTicketSource implements SemanticTicketSourceInterface
{
    /**
     * Safety cap for a synchronous request. If this cap is reached the result
     * explicitly reports sourceScanTruncated=true so the caller cannot present
     * the analysis as exhaustive.
     */
    private const MAX_SCAN_ROWS = 50000;

    private const DEFAULT_MAX_TICKETS = 500;
    private const MAX_SELECTED_TICKETS = 10000;

    /**
     * Bounds the amount of user-entered text sent to an external semantic
     * provider in one ticket while preserving enough material for ITSM intent
     * classification.
     */
    private const MAX_TITLE_CHARS = 1000;
    private const MAX_DESCRIPTION_CHARS = 12000;

    public function fetch(array $criteria): SemanticTicketSourceResult
    {
        global $DB;

        if (!$DB->tableExists('glpi_tickets')) {
            return new SemanticTicketSourceResult([], 0, 0, false, false, 0);
        }

        $maxTickets = (int) ($criteria['max_tickets'] ?? self::DEFAULT_MAX_TICKETS);
        $maxTickets = max(1, min(self::MAX_SELECTED_TICKETS, $maxTickets));

        $candidateCount = 0;
        $selected = [];
        $textTruncatedTickets = 0;
        $scanned = 0;

        foreach (
            $DB->request([
            'FROM' => 'glpi_tickets',
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['id DESC'],
            'LIMIT' => self::MAX_SCAN_ROWS,
            ]) as $row
        ) {
            if (!is_array($row)) {
                continue;
            }

            $scanned++;

            if (!$this->matches($row, $criteria)) {
                continue;
            }

            $candidateCount++;

            if (count($selected) >= $maxTickets) {
                continue;
            }

            [$title, $titleTruncated] = $this->cleanAndBound(
                (string) ($row['name'] ?? ''),
                self::MAX_TITLE_CHARS,
            );

            [$description, $descriptionTruncated] = $this->cleanAndBound(
                (string) ($row['content'] ?? ''),
                self::MAX_DESCRIPTION_CHARS,
            );

            if ($titleTruncated || $descriptionTruncated) {
                $textTruncatedTickets++;
            }

            $category = $this->categoryPath((int) ($row['itilcategories_id'] ?? 0));

            $selected[] = new SemanticTicketInput(
                (int) ($row['id'] ?? 0),
                $title,
                $description,
                $this->ticketTypeLabel((int) ($row['type'] ?? 0)),
                $category,
            );
        }

        $sourceScanTruncated = $scanned >= self::MAX_SCAN_ROWS;
        $selectionTruncated = $candidateCount > count($selected);

        return new SemanticTicketSourceResult(
            $selected,
            $candidateCount,
            count($selected),
            $selectionTruncated,
            $sourceScanTruncated,
            $textTruncatedTickets,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $criteria
     */
    private function matches(array $row, array $criteria): bool
    {
        $created = substr(
            (string) ($row['date'] ?? $row['date_creation'] ?? ''),
            0,
            10,
        );

        $from = trim((string) ($criteria['date_from'] ?? ''));
        $to = trim((string) ($criteria['date_to'] ?? ''));

        if ($from !== '' && $created !== '' && strcmp($created, $from) < 0) {
            return false;
        }

        if ($to !== '' && $created !== '' && strcmp($created, $to) > 0) {
            return false;
        }

        if (
            isset($criteria['entity_id'])
             && (int) $criteria['entity_id'] > 0
             && (int) ($row['entities_id'] ?? 0) !== (int) $criteria['entity_id']
        ) {
            return false;
        }

        if (
            isset($criteria['category_id'])
             && (int) $criteria['category_id'] > 0
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
     * Exact normalization already used by LocalGlpiProvider::cleanText(), then
     * an explicit character bound for semantic inference payload control.
     *
     * @return array{0:string,1:bool}
     */
    private function cleanAndBound(string $value, int $maxChars): array
    {
        $value = html_entity_decode(
            strip_tags($value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if (mb_strlen($value, 'UTF-8') <= $maxChars) {
            return [$value, false];
        }

        return [mb_substr($value, 0, $maxChars, 'UTF-8'), true];
    }

    private function ticketTypeLabel(int $type): ?string
    {
        return match ($type) {
            1 => 'Incident',
            2 => 'Request',
            default => $type > 0 ? (string) $type : null,
        };
    }

    private function categoryPath(int $id): ?string
    {
        global $DB;

        static $cache = [];

        if ($id <= 0) {
            return null;
        }

        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }

        if (!$DB->tableExists('glpi_itilcategories')) {
            return $cache[$id] = 'Category #' . $id;
        }

        foreach (
            $DB->request([
            'SELECT' => ['name', 'completename'],
            'FROM' => 'glpi_itilcategories',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
            ]) as $row
        ) {
            $path = trim((string) ($row['completename'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));

            return $cache[$id] = (
                $path !== ''
                    ? $path
                    : ($name !== '' ? $name : 'Category #' . $id)
            );
        }

        return $cache[$id] = 'Category #' . $id;
    }
}
