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

namespace GlpiPlugin\Uxconnector\Infrastructure\Zendesk;

/** Zendesk :: label segments become native GLPI parent/child ITIL categories. */
final class ZendeskTopicPath
{
    /** @return list<string> */
    public static function segments(string $label): array
    {
        $parts = preg_split('/\s*::\s*/u', trim($label)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
    }
}
