<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;

/** Extracts a list of records from a decoded JSON response using configuration only. */
final class CollectionExtractor
{
    public function __construct(private readonly JsonPathValueReader $reader)
    {
    }

    /**
    * @param array<mixed> $decoded
    *
    * @return list<mixed>
    */
    public function extract(array $decoded, CollectionPath $path): array
    {
        $value = $path->isRoot() ? $decoded : $this->reader->read($decoded, $path->value());

        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException(sprintf(
                'Collection path "%s" did not resolve to a JSON array.',
                $path->value(),
            ));
        }

        return $value;
    }
}
