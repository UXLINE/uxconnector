<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Fields;

use DBmysql;
use GlpiPlugin\Uxconnector\Application\Sync\Service\TargetFieldsCatalogInterface;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\GlpiTargetFieldsWriter;

/** Displays only active, real Fields attributes for the selected GLPI type. */
final class FieldsTargetCatalog implements TargetFieldsCatalogInterface
{
    public function __construct(private readonly DBmysql $db) {}

    /** @return list<string> */
    public function writableFor(string $itemtype): array
    {
        try {
            return (new GlpiTargetFieldsWriter($this->db))->writableTargetsFor($itemtype);
        } catch (\Throwable) {
            // Fields is optional. Never break the interaction editor when
            // the addon is absent or awaiting installation.
            return [];
        }
    }
}
