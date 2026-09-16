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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Repository;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

interface SyncExecutionRepositoryInterface
{
   public function save(SyncExecution $execution): SyncExecution;

   public function findById(SyncExecutionId $id): ?SyncExecution;

    /**
     * Execution history for a given SyncJob, most recent first.
     *
     * @return list<SyncExecution>
     */
   public function findBySyncJobId(SyncJobId $syncJobId): array;

    /**
     * Permanently removes a single execution record.
     *
     * Not yet used by any production code path — SyncExecution is
     * meant to accumulate as a history log, purged in bulk by a
     * future retention policy (see the project's roadmap), not
     * deleted one at a time by application code. Exists today so
     * tests/Integration can clean up after themselves without
     * leaving execution rows behind on a persistent CI database.
     */
   public function delete(SyncExecutionId $id): void;
}
