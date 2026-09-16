<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\DTO\SyncJobDetailsDTO;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

final class GetSyncJobService
{
   public function __construct(
      private readonly SyncJobRepositoryInterface $repository
   ) {
   }

   public function execute(SyncJobId $id): SyncJobDetailsDTO
   {
      $syncJob = $this->repository->findById($id);

      if ($syncJob === null) {
         throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::forId($id);
      }

      return SyncJobDetailsDTO::fromEntity($syncJob);
   }
}
