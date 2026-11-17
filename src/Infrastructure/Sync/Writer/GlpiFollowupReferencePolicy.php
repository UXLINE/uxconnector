<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Writer;

use DBmysql;

/** Authorizes local follow-up references before a native GLPI write, including stored jobs. */
final readonly class GlpiFollowupReferencePolicy
{
    public function __construct(private DBmysql $db)
    {
    }

    /** @param array<string,mixed> $data @return array<string,mixed> Validated local references. */
    public function validate(array $data, ?int $entityId, bool $linkedAuthor): array
    {
        if ($entityId === null || $entityId < 0) {
            throw new \RuntimeException('Follow-up imports require an explicit target entity.');
        }
        $type = $data['itemtype'] ?? '';
        $parent = match ($type) {
            'Ticket' => new \Ticket(),
            'Change' => new \Change(),
            'Problem' => new \Problem(),
            default => throw new \RuntimeException('Unsupported follow-up parent type.'),
        };
        $id = filter_var($data['items_id'] ?? null, FILTER_VALIDATE_INT);
        if (
            $id === false || $id <= 0 || !$parent->getFromDB($id)
            || !isset($parent->fields['entities_id']) || (int) $parent->fields['entities_id'] !== $entityId
            || (int) ($parent->fields['is_deleted'] ?? 0) !== 0
        ) {
            throw new \RuntimeException('Follow-up parent is unavailable in the interaction entity.');
        }
        $author = filter_var($data['users_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($author === false || $author < 0 || ($author > 0 && !$linkedAuthor)) {
            throw new \RuntimeException('Follow-up authors require an imported user ObjectLink, or system author 0.');
        }
        if ($author > 0) {
            $user = new \User();
            if (
                !$user->getFromDB($author) || (int) ($user->fields['is_active'] ?? 0) !== 1
                || (int) ($user->fields['is_deleted'] ?? 0) !== 0
            ) {
                throw new \RuntimeException('Follow-up author is unavailable.');
            }
            // Native GLPI resolves recursive profile assignments and descendant entities.
            $entities = array_map('intval', \Profile_User::getUserEntities($author, true));
            $found = in_array($entityId, $entities, true);
            if (!$found) {
                throw new \RuntimeException('Follow-up author has no profile assignment in the interaction entity.');
            }
        }
        $data['items_id'] = $id;
        $data['users_id'] = $author;
        return $data;
    }
}
