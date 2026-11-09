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

/**
 * Small deterministic JSON-path reader used by the generic Sync engine.
 *
 * Supported examples:
 *   $
 *   fields.summary
 *   $.fields.status.name
 *   attachments[0].filename
 *   $['page.next-link']
 *   metadata['page.info'].cursor
 *
 * This is intentionally not a complete JSONPath implementation. It supports
 * object keys, numeric array indexes and quoted bracket keys so APIs whose
 * field names contain dots can still be described without vendor-specific
 * code in the engine.
 *
 * `@literal:<value>` remains available for mapping constants.
 */
final class JsonPathValueReader
{
    /**
     * @param array<mixed> $payload
     */
    public function read(array $payload, string $path): mixed
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, '@literal:')) {
            return $this->decodeLiteral(substr($path, 9));
        }

        if ($path === '$') {
            return $payload;
        }

        if (str_starts_with($path, '$.')) {
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '$[')) {
            $path = substr($path, 1);
        }

        $tokens = $this->tokenize($path);
        $current = $payload;

        foreach ($tokens as $token) {
            if (!is_array($current) || !array_key_exists($token, $current)) {
                return null;
            }

            $current = $current[$token];
        }

        return $current;
    }

    /** @return list<int|string> */
    private function tokenize(string $path): array
    {
        $tokens = [];
        $length = strlen($path);
        $offset = 0;

        while ($offset < $length) {
            if ($path[$offset] === '.') {
                $offset++;
                if ($offset >= $length) {
                    throw new \InvalidArgumentException(sprintf('Invalid JSON path "%s".', $path));
                }
            }

            if ($path[$offset] === '[') {
                $end = strpos($path, ']', $offset);
                if ($end === false) {
                    throw new \InvalidArgumentException(sprintf('Invalid JSON path "%s".', $path));
                }

                $inside = substr($path, $offset + 1, $end - $offset - 1);
                if (preg_match('/^\d+$/D', $inside) === 1) {
                    $tokens[] = (int) $inside;
                } elseif (strlen($inside) >= 2
                     && (($inside[0] === "'" && $inside[strlen($inside) - 1] === "'")
                         || ($inside[0] === '"' && $inside[strlen($inside) - 1] === '"'))
                ) {
                    $tokens[] = stripcslashes(substr($inside, 1, -1));
                } else {
                    throw new \InvalidArgumentException(sprintf('Invalid JSON path bracket segment "[%s]".', $inside));
                }

                $offset = $end + 1;
                continue;
            }

            $start = $offset;
            while ($offset < $length && $path[$offset] !== '.' && $path[$offset] !== '[') {
                $offset++;
            }

            $segment = substr($path, $start, $offset - $start);
            if ($segment === '') {
                throw new \InvalidArgumentException(sprintf('Invalid JSON path "%s".', $path));
            }
            $tokens[] = $segment;
        }

        return $tokens;
    }

    private function decodeLiteral(string $literal): mixed
    {
        $trimmed = trim($literal);

        if ($trimmed === '') {
            return '';
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() === JSON_ERROR_NONE && !is_array($decoded) && !is_object($decoded)) {
            return $decoded;
        }

        return $literal;
    }
}
