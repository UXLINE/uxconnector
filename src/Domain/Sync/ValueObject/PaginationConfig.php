<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\ValueObject;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\PaginationType;

/**
 * Declarative pagination configuration owned by SyncJob.
 *
 * The engine only knows generic pagination semantics. Response field names
 * and request parameter names are configuration data supplied by a job or a
 * JobTemplate; no external product name appears in the runtime algorithm.
 */
final readonly class PaginationConfig
{
    /** @param array<string, scalar|null> $options */
    public function __construct(
        private PaginationType $type,
        private array $options = []
    ) {
        $this->validate();
    }

    public static function none(): self
    {
        return new self(PaginationType::NONE);
    }

    /** @param array<string, scalar|null> $options */
    public static function fromArray(string $type, array $options = []): self
    {
        $enum = PaginationType::tryFrom($type);
        if ($enum === null) {
            throw new \InvalidArgumentException(sprintf('Unknown pagination type "%s".', $type));
        }

        return new self($enum, $options);
    }

    public function type(): PaginationType
    {
        return $this->type;
    }

    /** @return array<string, scalar|null> */
    public function options(): array
    {
        return $this->options;
    }

    public function option(string $name): string|int|float|bool|null
    {
        return $this->options[$name] ?? null;
    }

    /** @return array{type:string,options:array<string,scalar|null>} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'options' => $this->options];
    }

    private function validate(): void
    {
        $required = match ($this->type) {
            PaginationType::NONE => [],
            PaginationType::CURSOR => ['cursor_response_path', 'cursor_request_parameter'],
            PaginationType::OFFSET => [
                'offset_response_path',
                'offset_request_parameter',
                'page_size_response_path',
                'total_response_path',
            ],
            PaginationType::COUNT_OFFSET => [
                'offset_request_parameter',
                'page_size',
            ],
            PaginationType::RANGE => [
                'range_request_parameter',
                'page_size',
                'total_response_path',
            ],
            PaginationType::NEXT_LINK => ['next_link_response_path'],
        };

        foreach ($required as $key) {
            $value = $this->options[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException(sprintf(
                    'Pagination option "%s" is required for type "%s".',
                    $key,
                    $this->type->value,
                ));
            }
        }
    }
}
