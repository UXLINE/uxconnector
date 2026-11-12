<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\LinkedTargetReference;
use GlpiPlugin\Uxconnector\Application\Sync\Transform\FieldTransformInterface;

/**
 * Deterministic transformations available to FieldMapping.
 *
 * In addition to named transforms, `map:` provides a configurable value map:
 *
 *   map:Highest=6|High=5|Medium=3|Low=2|Lowest=1
 *   map:new=1|indeterminate=2|done=5|*=1
 *
 * The `*` entry is an optional fallback. Mapped scalar values are decoded as
 * JSON scalars when possible so numeric GLPI fields receive integers rather
 * than strings.
 */
final class FieldValueTransformer
{
    private const SUPPORTED = [
        'trim',
        'uppercase',
        'lowercase',
        'date_iso8601',
        'datetime_glpi',
        'integer',
        'boolean_int',
    ];

    /** @var array<string, FieldTransformInterface> */
    private array $extensions = [];

    /** @param iterable<FieldTransformInterface> $extensions */
    public function __construct(iterable $extensions = [])
    {
        foreach ($extensions as $extension) {
            $name = trim($extension->name());
            if (
                $name === '' || isset($this->extensions[$name]) || in_array(
                    $name,
                    self::SUPPORTED,
                    true
                ) || str_starts_with($name, 'map:')
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid or duplicate field transform extension "%s".',
                    $name
                ));
            }
            $this->extensions[$name] = $extension;
        }
    }

    public function apply(mixed $value, ?string $transform): mixed
    {
        if ($transform === null || trim($transform) === '') {
            return $value;
        }

        $transform = trim($transform);

        if (str_starts_with($transform, 'link:')) {
            $externalType = substr($transform, 5);
            if (!is_scalar($value) || trim((string) $value) === '') {
                throw new \InvalidArgumentException('Cannot resolve an empty ObjectLink source identifier.');
            }
            return new LinkedTargetReference($externalType, trim((string) $value));
        }

        if (str_starts_with($transform, 'map:')) {
            return $this->mapValue($value, substr($transform, 4));
        }

        if (isset($this->extensions[$transform])) {
            return $this->extensions[$transform]->apply($value);
        }

        if (!in_array($transform, self::SUPPORTED, true)) {
            $supported = array_merge(self::SUPPORTED, array_keys($this->extensions));
            throw new \InvalidArgumentException(sprintf(
                'Unsupported field transform "%s". Supported: %s, map:key=value|key=value.',
                $transform,
                implode(', ', $supported),
            ));
        }

        return match ($transform) {
            'trim' => is_string($value) ? trim($value) : $value,
            'uppercase' => is_string($value) ? mb_strtoupper($value) : $value,
            'lowercase' => is_string($value) ? mb_strtolower($value) : $value,
            'date_iso8601' => $this->toIso8601($value),
            'datetime_glpi' => $this->toGlpiDateTime($value),
            'integer' => $this->toInteger($value),
            'boolean_int' => $this->toBooleanInt($value),
        };
    }

    private function toIso8601(mixed $value): string
    {
        return $this->toDateTime($value, 'date_iso8601')->format(DateTimeImmutable::ATOM);
    }

    private function toGlpiDateTime(mixed $value): string
    {
        return $this->toDateTime($value, 'datetime_glpi')->format('Y-m-d H:i:s');
    }

    private function toDateTime(mixed $value, string $transform): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException(sprintf(
                'Cannot apply "%s" transform to a non-date value (%s).',
                $transform,
                get_debug_type($value),
            ));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new \RuntimeException(sprintf(
                'Cannot apply "%s" transform: "%s" is not a parsable date.',
                $transform,
                $value,
            ), previous: $exception);
        }
    }

    private function toInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\\d+$/D', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw new \RuntimeException(sprintf('Cannot convert %s to integer.', get_debug_type($value)));
    }

    private function toBooleanInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));
            if (in_array($normalised, ['1', 'true', 'yes', 'on'], true)) {
                return 1;
            }
            if (in_array($normalised, ['0', 'false', 'no', 'off'], true)) {
                return 0;
            }
        }

        throw new \RuntimeException(sprintf('Cannot convert %s to boolean integer.', get_debug_type($value)));
    }

    private function mapValue(mixed $value, string $definition): mixed
    {
        $map = [];
        foreach (explode('|', $definition) as $rule) {
            if ($rule === '') {
                continue;
            }

            $parts = explode('=', $rule, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '') {
                throw new \InvalidArgumentException(sprintf('Malformed map rule "%s".', $rule));
            }

            $map[trim($parts[0])] = $this->decodeScalar(trim($parts[1]));
        }

        $key = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        if (array_key_exists($key, $map)) {
            return $map[$key];
        }

        if (array_key_exists('*', $map)) {
            return $map['*'];
        }

        throw new \RuntimeException(sprintf('No value mapping exists for "%s".', $key));
    }

    private function decodeScalar(string $value): mixed
    {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && !is_array($decoded) && !is_object($decoded)) {
            return $decoded;
        }

        return $value;
    }
}
