<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use DBmysql;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/** Applies the configured entity to every native lookup and creation in legacy migrations. */
final class MigrationEntityScope
{
    /** @param array<string|int,mixed> $record Source record used for mapped entity strategies. */
    public static function resolve(SyncJob $job, array $record): int
    {
        $id = $job->entityAssignmentStrategy()?->resolve($record);
        if ($id === null || $id < 0 || !(new \Entity())->getFromDB($id)) {
            throw new \RuntimeException('TARGET_ENTITY_MISSING_OR_INVALID');
        }
        return $id;
    }

    /** @param array<string,mixed> $criteria @return array<string,mixed> */
    public static function constrain(DBmysql $db, string $table, array $criteria, ?int $entityId): array
    {
        if ($entityId === null) {
            throw new \RuntimeException('TARGET_ENTITY_NOT_RESOLVED');
        }
        if ($db->fieldExists($table, 'entities_id')) {
            if (isset($criteria['entities_id']) && (int) $criteria['entities_id'] !== $entityId) {
                throw new \RuntimeException('TARGET_ENTITY_CONFLICT');
            }
            $criteria['entities_id'] = $entityId;
        }
        return $criteria;
    }
}
