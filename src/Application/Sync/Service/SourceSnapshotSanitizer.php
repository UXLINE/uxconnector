<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

/** Redacts common credential-bearing keys before a failed source record is persisted. */
final class SourceSnapshotSanitizer
{
    private const SECRET_FRAGMENTS = ['authorization','token','password','passwd','secret','api_key','apikey','cookie','session','puk','pin','iccid'];

    public function encode(array $record): ?string
    {
        $redacted = $this->redact($record);
        try {
            $json = json_encode($redacted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return null;
        }
        if (strlen($json) > 65535) {
            return substr($json, 0, 65535);
        }
        return $json;
    }

    private function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            $secret = false;
            foreach (self::SECRET_FRAGMENTS as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    $secret = true;
                    break;
                }
            }
            $out[$key] = $secret ? '[REDACTED]' : $this->redact($item);
        }
        return $out;
    }
}
