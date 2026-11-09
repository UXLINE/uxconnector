<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\OutboundConfig;

/** Parses the Advanced REST outbound editor into the generic OutboundConfig VO. */
final class OutboundConfigRequestParser
{
    /** @param array<string,mixed> $request */
    public function parse(array $request): OutboundConfig
    {
        $create = $this->parseRequestDefinition($request, 'outbound_create');
        $update = $this->parseRequestDefinition($request, 'outbound_update');

        return OutboundConfig::fromArray([
            'create' => $create,
            'update' => $update,
            'response_external_id_path' => trim((string) ($request['outbound_response_external_id_path'] ?? 'id')) ?: 'id',
        ]);
    }

    /**
    * @param array<string, mixed> $request
    *
    * @return array<string, mixed>|null
    */
    private function parseRequestDefinition(array $request, string $prefix): ?array
    {
        $method = strtoupper(trim((string) ($request[$prefix . '_method'] ?? '')));
        $path = trim((string) ($request[$prefix . '_path'] ?? ''));
        $bodyJson = trim((string) ($request[$prefix . '_body'] ?? ''));

        if ($method === '' && $path === '' && $bodyJson === '') {
            return null;
        }
        if ($method === '' || $path === '') {
            throw new \RuntimeException(sprintf('%s method and path must both be provided.', ucfirst(str_replace('_', ' ', $prefix))));
        }

        $definition = ['method' => $method, 'path' => $path];
        if ($bodyJson !== '') {
            try {
                $body = json_decode($bodyJson, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException(sprintf('%s body must be valid JSON.', ucfirst(str_replace('_', ' ', $prefix))), previous: $exception);
            }
            if (!is_array($body)) {
                throw new \RuntimeException(sprintf('%s body must be a JSON object or array.', ucfirst(str_replace('_', ' ', $prefix))));
            }
            $definition['body'] = $body;
        }

        return $definition;
    }
}
