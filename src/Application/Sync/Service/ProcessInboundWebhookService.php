<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\InteractionLookupRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;

/** Executes one active inbound webhook interaction identified by its opaque key. */
final class ProcessInboundWebhookService
{
   public function __construct(
        private readonly InteractionLookupRepositoryInterface $syncJobRepository,
        private readonly SyncExecutionRepositoryInterface $executionRepository,
        private readonly AuthConfigRepositoryInterface $authConfigRepository,
        private readonly InboundRecordProcessor $processor,
        private readonly CollectionExtractor $collectionExtractor,
        private readonly TargetFieldPolicy $targetFieldPolicy,
        private readonly TransactionManagerInterface $transaction,
        private readonly EventDispatcherInterface $events
    ) {
   }

   /**
    * @param array<mixed> $payload
    *
    * @return array{
    *     status: string,
    *     processed: int,
    *     failed: int
    * }
    */
   public function execute(string $webhookKey, array $payload): array {
       $job = $this->syncJobRepository->findByWebhookKey($webhookKey);
      if ($job === null || $job->triggerType() !== InteractionTrigger::WEBHOOK || $job->direction() !== SyncDirection::INBOUND) {
          throw new \RuntimeException('Unknown or inactive webhook interaction.');
      }

       $jobId = $job->id();
      if ($jobId === null) {
          throw new \RuntimeException('Webhook interaction is not persisted.');
      }

       $reference = $job->authConfigReference();
       $connection = $this->authConfigRepository->findById(new AuthConfigId($reference->value()));
      if ($connection === null) {
          throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigUnavailableException::referenceNotFound($reference);
      }
      if (!$connection->isUsable()) {
          throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigUnavailableException::notUsable($reference);
      }

       $this->targetFieldPolicy->assertConfiguration($job->targetItemtype(), $job->matchField(), $job->fieldMappings());
       $execution = $this->save(SyncExecution::start($jobId));

      try {
          $items = $job->collectionPath()->isRoot()
              ? [$payload]
              : $this->collectionExtractor->extract($payload, $job->collectionPath());
          [$processed, $failed] = $this->processor->process($job, $items);
          $execution->complete($processed, $failed);
          $this->saveFinished($execution);
          return ['status' => $execution->status()->value, 'processed' => $processed, 'failed' => $failed];
      } catch (\Throwable $exception) {
          $execution->fail($exception->getMessage());
          $this->saveFinished($execution);
          throw $exception;
      }
   }

   private function save(SyncExecution $execution): SyncExecution {
       $this->transaction->begin();
      try {
          $saved = $this->executionRepository->save($execution);
          $this->transaction->commit();
          return $saved;
      } catch (\Throwable $e) {
          $this->transaction->rollback();
          throw $e;
      }
   }

   private function saveFinished(SyncExecution $execution): void {
       $events = $execution->releaseEvents();
       $this->transaction->begin();
      try {
          $this->executionRepository->save($execution);
          $this->transaction->commit();
      } catch (\Throwable $e) {
          $this->transaction->rollback();
          throw $e;
      }
      foreach ($events as $event) {
          $this->events->dispatch($event);
      }
   }
}
