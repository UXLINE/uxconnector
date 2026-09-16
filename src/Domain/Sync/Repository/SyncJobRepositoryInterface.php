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

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

interface SyncJobRepositoryInterface
{
   public function save(SyncJob $syncJob): SyncJob;

   public function findById(SyncJobId $id): ?SyncJob;

    /**
     * @return list<SyncJob>
     */
   public function findAll(): array;

    /**
     * Returns every SyncJob eligible for execution right now
     * (status ACTIVE). Used by SyncJobCronTask.
     *
     * @return list<SyncJob>
     */
   public function findRunnable(): array;


   public function delete(SyncJobId $id): void;

   public function exists(SyncJobId $id): bool;
}
