<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\ValueObject;

/**
 * Connector-agnostic description of outbound create/update HTTP operations.
 * Body values are resolved at runtime from the GLPI item using @field:<name>,
 * while template parameters have already been substituted at activation time.
 */
final readonly class OutboundConfig
{
    /**
    * @param array<string, mixed>|null $createRequest
    * @param array<string, mixed>|null $updateRequest
    */
    private function __construct(
        public ?array $createRequest,
        public ?array $updateRequest,
        public string $responseExternalIdPath
    ) {
        if ($this->createRequest === null && $this->updateRequest === null) {
            return;
        }
        if (trim($this->responseExternalIdPath) === '') {
            throw new \InvalidArgumentException('Outbound response external ID path must not be empty.');
        }
        foreach ([$this->createRequest, $this->updateRequest] as $request) {
            if ($request === null) {
                continue;
            }
            $method = strtoupper((string) ($request['method'] ?? ''));
            if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                throw new \InvalidArgumentException(sprintf('Unsupported outbound HTTP method "%s".', $method));
            }
            if (trim((string) ($request['path'] ?? '')) === '') {
                throw new \InvalidArgumentException('Outbound request path must not be empty.');
            }
            if (isset($request['body']) && !is_array($request['body'])) {
                throw new \InvalidArgumentException('Outbound request body must be a JSON object/array definition.');
            }
        }
    }

    public static function none(): self
    {
        return new self(null, null, 'id');
    }

    /** @param array<string,mixed>|null $payload */
    public static function fromArray(?array $payload): self
    {
        if ($payload === null || $payload === []) {
            return self::none();
        }

        return new self(
            isset($payload['create']) && is_array($payload['create']) ? $payload['create'] : null,
            isset($payload['update']) && is_array($payload['update']) ? $payload['update'] : null,
            (string) ($payload['response_external_id_path'] ?? 'id'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->createRequest !== null || $this->updateRequest !== null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'create' => $this->createRequest,
            'update' => $this->updateRequest,
            'response_external_id_path' => $this->responseExternalIdPath,
        ];
    }
}
