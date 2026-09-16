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

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncExecutionStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final class SyncExecutionPersistenceMapper
{
    /**
     * @param array<string, mixed> $row
     */
   public function toDomain(array $row): SyncExecution {
       $this->assertHasRequiredFields($row);

       $status = SyncExecutionStatus::tryFrom((string) $row['status']);

      if ($status === null) {
          throw new \RuntimeException(sprintf(
              'Corrupted SyncExecution row #%s: unknown status value.',
              (string) $row['id']
          ));
      }

       return SyncExecution::reconstitute(
           new SyncExecutionId((int) $row['id']),
           new SyncJobId((int) $row['plugin_uxconnector_syncjobs_id']),
           $status,
           new DateTimeImmutable((string) $row['started_at']),
           $this->nullableDate($row['finished_at'] ?? null),
           (int) $row['items_processed'],
           (int) $row['items_failed'],
           $row['error_message'] !== null && $row['error_message'] !== ''
               ? (string) $row['error_message']
               : null
       );
   }

    /**
     * @return array<string, int|string|null>
     */
   public function toPersistence(SyncExecution $execution): array {
       $data = [
           'plugin_uxconnector_syncjobs_id' => $execution->syncJobId()->value(),
           'status'                         => $execution->status()->value,
           'started_at'                     => $execution->startedAt()->format('Y-m-d H:i:s'),
           'finished_at'                    => $execution->finishedAt()?->format('Y-m-d H:i:s'),
           'items_processed'                => $execution->itemsProcessed(),
           'items_failed'                   => $execution->itemsFailed(),
           'error_message'                  => $execution->errorMessage(),
       ];

       $id = $execution->id();

       if ($id !== null) {
           $data['id'] = $id->value();
       }

       return $data;
   }

   public function assignGeneratedId(SyncExecution $execution, int $generatedId): void {
       $execution->assignId(new SyncExecutionId($generatedId));
   }

   private function nullableDate(mixed $value): ?DateTimeImmutable {
      if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
          return null;
      }

       return new DateTimeImmutable((string) $value);
   }

    /**
     * @param array<string, mixed> $row
     */
   private function assertHasRequiredFields(array $row): void {
      foreach (['id', 'plugin_uxconnector_syncjobs_id', 'status', 'started_at', 'items_processed', 'items_failed']
           as $field
       ) {
         if (!array_key_exists($field, $row)) {
             throw new \RuntimeException(sprintf(
                 'Cannot hydrate SyncExecution: missing "%s" field in persisted row.',
                 $field
             ));
         }
      }
   }
}
