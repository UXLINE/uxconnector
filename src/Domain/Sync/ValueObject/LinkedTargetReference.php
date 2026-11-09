<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\ValueObject;

/** Deferred ObjectLink lookup. Source IDs are never target GLPI IDs. */
final readonly class LinkedTargetReference
{
    public function __construct(public string $externalObjectType, public string $externalId)
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $externalObjectType) !== 1 || trim($externalId) === '') {
            throw new \InvalidArgumentException('Invalid ObjectLink reference.');
        }
    }
}
