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

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;

/**
 * Converts the parallel-array shape produced by the SyncJob creation
 * form's repeated rows (source_field[], target_field[], transform[],
 * required[]) into a list<FieldMapping>.
 *
 * Kept as a standalone class rather than inlined in
 * SyncJobController::create() so it can be unit-tested without any
 * GLPI dependency (RULE-CTRL-001 : a Controller adapts HTTP
 * parameters, but the adaptation logic itself is worth isolating and
 * testing on its own once it does more than a one-line cast).
 */
final class FieldMappingRequestParser
{
    private const ALLOWED_TRANSFORMS = [
        'trim', 'uppercase', 'lowercase', 'date_iso8601', 'datetime_glpi',
        'integer', 'boolean_int', 'jira_adf_to_text',
    ];
    /**
     * @param array<string, mixed> $request Typically $_POST.
     *
     * @return list<FieldMapping>
     */
    public function parse(array $request): array
    {
        $sourceFields = $request['source_field'] ?? [];
        $targetFields = $request['target_field'] ?? [];
        $transforms = $request['transform'] ?? [];
        $requiredFlags = $request['required'] ?? [];

        if (!is_array($sourceFields) || !is_array($targetFields)) {
            return [];
        }

        $mappings = [];

        foreach ($sourceFields as $index => $sourceField) {
            $sourceField = trim((string) $sourceField);
            $targetField = trim((string) ($targetFields[$index] ?? ''));

            // A row left entirely blank by the visitor is silently
            // skipped - the form always renders a few empty rows to
            // fill in, not every one needs to be used.
            if ($sourceField === '' && $targetField === '') {
                continue;
            }

            if ($sourceField === '' || $targetField === '') {
                throw new \InvalidArgumentException(sprintf(
                    'Incomplete field mapping on row %d: source and target fields are both required.',
                    $index + 1,
                ));
            }

            $transform = isset($transforms[$index]) ? trim((string) $transforms[$index]) : '';

            if (
                $transform !== ''
                 && !in_array($transform, self::ALLOWED_TRANSFORMS, true)
                 && !str_starts_with($transform, 'map:')
                 && preg_match('/^link:[a-z0-9][a-z0-9_.-]{0,63}$/D', $transform) !== 1
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported field transform "%s" on row %d.',
                    $transform,
                    $index + 1,
                ));
            }

            $mappings[] = new FieldMapping(
                $sourceField,
                $targetField,
                required: isset($requiredFlags[$index]),
                transform: $transform !== '' ? $transform : null,
            );
        }

        return $mappings;
    }
}
