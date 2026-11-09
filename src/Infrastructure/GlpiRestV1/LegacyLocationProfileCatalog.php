<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;

/** An isolated catalog per source connection and profile, with fail-fast matching. */
final class LegacyLocationProfileCatalog
{
    /** @var array<string,array<string,int>> */
    private array $cache = [];

    public function __construct(private readonly HttpEngineInterface $http) {}

    /** @return array<string,int> */
    public function resolve(AuthConfig $auth, string $profile): array
    {
        $key = ($auth->id()?->value() ?? spl_object_id($auth)) . ':' . $profile;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        // The default API view removes linkfield/joinparams; raw metadata is
        // required to distinguish the location from its parent. No records are read.
        $response = $this->http->execute($auth, new HttpRequest(HttpMethod::GET, '/listSearchOptions/Location?raw=1'));
        if (!$response->isSuccessful()) {
            throw new \RuntimeException('GLPI source search option discovery failed (HTTP ' . $response->statusCode . ').');
        }
        $options = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($options)) {
            throw new \RuntimeException('GLPI Location search options response is invalid.');
        }
        return $this->cache[$key] = self::match($options, $profile);
    }

    /** @param array<string|int,mixed> $options @return array<string,int> */
    public static function match(array $options, string $profile): array
    {
        $definitions = LegacyLocationMigrationProfiles::definitions($profile);
        $resolved = [];
        $missing = [];
        foreach (['source_id' => null, 'full_name' => null] + $definitions as $logical => $definition) {
            $scores = [];
            foreach ($options as $optionId => $option) {
                if (!is_numeric($optionId) || !is_array($option)) {
                    continue;
                }
                $id = (int) $optionId;
                $table = self::norm((string) ($option['table'] ?? ''));
                $field = self::norm((string) ($option['field'] ?? ''));
                $name = self::norm((string) ($option['name'] ?? ''));
                $group = self::norm((string) ($option['groupname'] ?? $option['group'] ?? ''));
                $metadata = self::norm(implode(' ', array_map(
                    static fn($v): string => is_scalar($v) ? (string) $v : '',
                    [$option['table'] ?? '', $option['field'] ?? '', $option['linkfield'] ?? ''],
                )));
                $score = 0;
                if ($logical === 'source_id') {
                    $score = $table === 'glpilocations' && $field === 'id' && self::isOwnLocationOption($option) ? 120 : 0;
                } elseif ($logical === 'full_name') {
                    $score = $table === 'glpilocations' && self::isOwnLocationOption($option)
                        && in_array($field, ['completename', 'name'], true)
                        ? ($field === 'completename' ? 120 : 40) : 0;
                } else {
                    $xml = self::norm($definition['xml']);
                    $block = self::norm($definition['block']);
                    $isFields = str_contains($metadata, 'pluginfields')
                        || str_contains($group, 'plugin') || str_contains($group, 'champssupplementaires');
                    $tableIsBlock = str_contains($table, $block);
                    if ($isFields && ($field === $xml || str_ends_with($field, $xml))) {
                        $score = $tableIsBlock ? 120 : 100;
                    } elseif ($isFields && $xml !== '' && str_contains($metadata, $xml)) {
                        $score = $tableIsBlock ? 115 : 95;
                    } elseif ($isFields && $name === self::norm($definition['label']) && $tableIsBlock) {
                        $score = 85;
                    } elseif ($isFields && $name === self::norm($definition['label'])) {
                        $score = 60;
                    }
                }
                if ($score > 0) {
                    $scores[$id] = $score;
                }
            }
            if ($scores === []) {
                $missing[] = is_array($definition) ? $definition['label'] . ' [' . $definition['block'] . ']' : $logical;
                continue;
            }
            $best = max($scores);
            $bestIds = array_keys(array_filter($scores, static fn(int $score): bool => $score === $best));
            if (count($bestIds) !== 1) {
                throw new \RuntimeException('Ambiguous source Location option for ' . $logical . ' (options ' . implode(', ', $bestIds) . '). These are API search field identifiers, not Location record IDs. No target writes performed.');
            }
            $resolved[$logical] = (int) $bestIds[0];
        }
        if ($missing !== []) {
            throw new \RuntimeException('Missing source Location options for ' . $profile . ': ' . implode(', ', $missing) . '. No target writes performed.');
        }
        foreach (['source_updated_at' => 'date_mod', 'source_created_at' => 'date_creation'] as $logical => $field) {
            $ids = [];
            foreach ($options as $optionId => $option) {
                if (is_numeric($optionId) && is_array($option)
                    && ($option['table'] ?? '') === 'glpi_locations'
                    && ($option['field'] ?? '') === $field && self::isOwnLocationOption($option)) {
                    $ids[] = (int) $optionId;
                }
            }
            if (count($ids) === 1) {
                $resolved[$logical] = $ids[0];
            }
        }
        $parents = [];
        foreach ($options as $optionId => $option) {
            if (is_numeric($optionId) && is_array($option)
                && ($option['table'] ?? '') === 'glpi_locations'
                && ($option['field'] ?? '') === 'completename'
                && ($option['linkfield'] ?? '') === 'locations_id') {
                $parents[] = (int) $optionId;
            }
        }
        if (count($parents) === 1) {
            $resolved['source_parent'] = $parents[0];
        }
        return $resolved;
    }

    /** @return list<int> */
    public function forcedDisplayIds(AuthConfig $auth, string $profile): array
    {
        $ids = array_values(array_unique(array_values($this->resolve($auth, $profile))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Search options on glpi_locations may describe the current location OR a
     * joined location (notably its parent). Both use field=completename.
     * Resolve by the relation metadata, never by a fixed numeric option ID or
     * translated label. A genuine duplicate of the own field still fails closed.
     *
     * @param array<string,mixed> $option
     */
    private static function isOwnLocationOption(array $option): bool
    {
        $link = $option['linkfield'] ?? '';
        if (!is_string($link)) {
            return false;
        }
        $link = strtolower(trim($link));
        $field = strtolower(trim((string) ($option['field'] ?? '')));
        if ($link !== '' && $link !== 'id' && $link !== $field) {
            return false;
        }
        $join = $option['joinparams'] ?? [];
        return $join === [] || $join === false;
    }

    private static function norm(string $text): string
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower(trim($text)) : strtolower(trim($text));
        $text = strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'ç' => 'c', '°' => '']);
        return preg_replace('/[^a-z0-9]/', '', $text) ?? '';
    }
}
