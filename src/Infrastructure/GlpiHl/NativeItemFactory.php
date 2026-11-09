<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiHl;

/** Uses GLPI's validated item-type resolution rather than arbitrary constructors. */
final class NativeItemFactory
{
    public static function create(string $itemtype): \CommonDBTM
    {
        $item = getItemForItemtype($itemtype);
        if (!$item instanceof \CommonDBTM) {
            throw new \RuntimeException('Target GLPI item type is unavailable or invalid.');
        }
        return $item;
    }
}
