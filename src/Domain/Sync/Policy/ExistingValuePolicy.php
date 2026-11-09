<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Policy;

/** Conservative replay: source data may fill gaps, never replace target values. */
final class ExistingValuePolicy
{
    public static function isEmpty(string $field, mixed $value): bool
    {
        if ($value === null || (is_string($value) && trim($value) === '') || $value === []) {
            return true;
        }
        // A zero foreign key means no association, except entity 0 which is
        // the real root entity. Numeric amounts and boolean false are values.
        return $field !== 'entities_id'
            && preg_match('/_id(?:_|$)/D', $field) === 1
            && ($value === 0 || $value === '0');
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    public static function additions(array $existing, array $incoming): array
    {
        $result = [];
        foreach ($incoming as $field => $value) {
            if ($field === 'id' || $field === 'entities_id') {
                continue;
            }
            if (self::isEmpty($field, $existing[$field] ?? null)
                && !self::isEmpty($field, $value)) {
                $result[$field] = $value;
            }
        }
        return $result;
    }
}
