<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\UpdateSyncJobCommand;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;

final class UpdateSyncJobService
{
   public function __construct(
      private readonly SyncJobRepositoryInterface $syncJobRepository,
      private readonly AuthConfigRepositoryInterface $authConfigRepository,
      private readonly TargetFieldPolicy $targetFieldPolicy,
      private readonly TransactionManagerInterface $transaction
   ) {
   }

   public function execute(UpdateSyncJobCommand $command): SyncJob {
      $syncJob = $this->syncJobRepository->findById($command->id);

      if ($syncJob === null) {
         throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException::forId(
            $command->id
         );
      }

      if ($syncJob->isRunnable()) {
         throw new \DomainException(
            'An active synchronization job must be paused before it can be modified.'
         );
      }

      $authConfig = $this->authConfigRepository->findById(
         new AuthConfigId($command->authConfigReference->value())
      );

      if ($authConfig === null) {
         throw new \RuntimeException(
            'The selected connection does not exist.'
         );
      }

      $this->targetFieldPolicy->assertConfiguration(
         $command->targetItemtype,
         $command->matchField,
         $command->fieldMappings
      );

      $webhookKey = null;

      if ($command->triggerType === InteractionTrigger::WEBHOOK) {
         $webhookKey = $syncJob->webhookKey()
            ?? bin2hex(random_bytes(32));
      }

      $syncJob->reconfigure(
         $command->name,
         $command->authConfigReference,
         $command->direction,
         $command->resourcePath,
         $command->targetItemtype,
         $command->matchField,
         $command->externalIdField,
         $command->fieldMappings,
         $command->entityAssignmentStrategy,
         $command->collectionPath,
         $command->paginationConfig,
         $command->triggerType,
         $command->externalObjectType,
         $command->outboundConfig,
         $webhookKey
      );

      $this->transaction->begin();

      try {
         $saved = $this->syncJobRepository->save($syncJob);
         $this->transaction->commit();

         return $saved;
      } catch (\Throwable $exception) {
         $this->transaction->rollback();
         throw $exception;
      }
   }
}
