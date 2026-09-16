<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\UpdateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Service\UpdateSyncJobService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\OutboundConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use PHPUnit\Framework\TestCase;

final class UpdateSyncJobServiceTest extends TestCase
{
   public function testPausedJobCanBeReconfiguredAndPersisted(): void {
      $job = $this->makeJob(SyncJobStatus::PAUSED);

      $repository = $this->createMock(SyncJobRepositoryInterface::class);
      $repository
         ->expects(self::once())
         ->method('findById')
         ->with(self::callback(
            static fn (SyncJobId $id): bool => $id->value() === 1
         ))
         ->willReturn($job);

      $repository
         ->expects(self::once())
         ->method('save')
         ->willReturnCallback(
            static fn (SyncJob $saved): SyncJob => $saved
         );

      $authConfigs = $this->authConfigRepositoryWith(
         $this->makeAuthConfig()
      );

      $transaction = $this->createMock(
         TransactionManagerInterface::class
      );
      $transaction->expects(self::once())->method('begin');
      $transaction->expects(self::once())->method('commit');
      $transaction->expects(self::never())->method('rollback');

      $result = $this->makeService(
         $repository,
         $authConfigs,
         $transaction
      )->execute(
         $this->makeCommand(
            name: 'Updated synchronization'
         )
      );

      self::assertSame(
         'Updated synchronization',
         $result->name()->value()
      );
      self::assertSame(
         SyncJobStatus::PAUSED,
         $result->status()
      );
      self::assertSame(
         '/updated-resource',
         $result->resourcePath()->value()
      );
      self::assertSame(
         'external_id',
         $result->externalIdField()->value()
      );
   }

   public function testActiveJobCannotBeModified(): void {
      $job = $this->makeJob(SyncJobStatus::ACTIVE);

      $repository = $this->createMock(SyncJobRepositoryInterface::class);
      $repository
         ->method('findById')
         ->willReturn($job);

      $repository
         ->expects(self::never())
         ->method('save');

      $authConfigs = $this->createMock(
         AuthConfigRepositoryInterface::class
      );
      $authConfigs
         ->expects(self::never())
         ->method('findById');

      $transaction = $this->createMock(
         TransactionManagerInterface::class
      );
      $transaction->expects(self::never())->method('begin');

      $this->expectException(\DomainException::class);
      $this->expectExceptionMessage('paused');

      $this->makeService(
         $repository,
         $authConfigs,
         $transaction
      )->execute($this->makeCommand());
   }

   public function testUnknownConnectionIsRejectedBeforePersistence(): void {
      $job = $this->makeJob(SyncJobStatus::DRAFT);

      $repository = $this->createMock(SyncJobRepositoryInterface::class);
      $repository->method('findById')->willReturn($job);
      $repository->expects(self::never())->method('save');

      $authConfigs = $this->createMock(
         AuthConfigRepositoryInterface::class
      );
      $authConfigs
         ->method('findById')
         ->willReturn(null);

      $transaction = $this->createMock(
         TransactionManagerInterface::class
      );
      $transaction->expects(self::never())->method('begin');

      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('selected connection');

      $this->makeService(
         $repository,
         $authConfigs,
         $transaction
      )->execute($this->makeCommand());
   }

   public function testRepositoryFailureRollsBackTransaction(): void {
      $job = $this->makeJob(SyncJobStatus::DRAFT);

      $repository = $this->createMock(SyncJobRepositoryInterface::class);
      $repository->method('findById')->willReturn($job);

      $repository
         ->expects(self::once())
         ->method('save')
         ->willThrowException(
            new \RuntimeException('database failure')
         );

      $transaction = $this->createMock(
         TransactionManagerInterface::class
      );
      $transaction->expects(self::once())->method('begin');
      $transaction->expects(self::never())->method('commit');
      $transaction->expects(self::once())->method('rollback');

      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('database failure');

      $this->makeService(
         $repository,
         $this->authConfigRepositoryWith($this->makeAuthConfig()),
         $transaction
      )->execute($this->makeCommand());
   }

   public function testExistingWebhookKeyIsPreserved(): void {
      $key = str_repeat('a', 64);

      $job = $this->makeJob(
         SyncJobStatus::PAUSED,
         InteractionTrigger::WEBHOOK,
         $key
      );

      $result = $this->executeSuccessfully(
         $job,
         $this->makeCommand(
            trigger: InteractionTrigger::WEBHOOK
         )
      );

      self::assertSame($key, $result->webhookKey());
   }

   public function testWebhookKeyIsGeneratedWhenSwitchingToWebhook(): void {
      $job = $this->makeJob(
         SyncJobStatus::DRAFT,
         InteractionTrigger::POLLING
      );

      self::assertNull($job->webhookKey());

      $result = $this->executeSuccessfully(
         $job,
         $this->makeCommand(
            trigger: InteractionTrigger::WEBHOOK
         )
      );

      self::assertNotNull($result->webhookKey());
      self::assertMatchesRegularExpression(
         '/^[a-f0-9]{64}$/',
         (string) $result->webhookKey()
      );
   }

   public function testWebhookKeyIsRemovedWhenLeavingWebhookMode(): void {
      $job = $this->makeJob(
         SyncJobStatus::PAUSED,
         InteractionTrigger::WEBHOOK,
         str_repeat('b', 64)
      );

      $result = $this->executeSuccessfully(
         $job,
         $this->makeCommand(
            trigger: InteractionTrigger::POLLING
         )
      );

      self::assertNull($result->webhookKey());
   }

   private function makeService(
      SyncJobRepositoryInterface $syncJobs,
      AuthConfigRepositoryInterface $authConfigs,
      TransactionManagerInterface $transaction
   ): UpdateSyncJobService {
      return new UpdateSyncJobService(
         $syncJobs,
         $authConfigs,
         new TargetFieldPolicy(),
         $transaction
      );
   }

   private function makeAuthConfig(): AuthConfig {
      $authConfig = AuthConfig::create(
         new AuthConfigName('Test connection'),
         new Endpoint('https://api.example.com'),
         AuthMethod::NONE
      );

      $authConfig->assignId(new AuthConfigId(1));

      return $authConfig;
   }

   private function authConfigRepositoryWith(
      AuthConfig $authConfig
   ): AuthConfigRepositoryInterface {
      $repository = $this->createMock(
         AuthConfigRepositoryInterface::class
      );

      $repository
         ->method('findById')
         ->willReturn($authConfig);

      return $repository;
   }

   private function makeJob(
      SyncJobStatus $status,
      InteractionTrigger $trigger = InteractionTrigger::POLLING,
      ?string $webhookKey = null
   ): SyncJob {
      $job = SyncJob::create(
         name: new SyncJobName('Original synchronization'),
         authConfigReference: new AuthConfigReference(1),
         direction: SyncDirection::INBOUND,
         resourcePath: new ResourcePath('/original-resource'),
         targetItemtype: TargetItemtype::COMPUTER,
         matchField: new MatchField('serial'),
         externalIdField: new ExternalIdField('id'),
         fieldMappings: [
            new FieldMapping(
               'serialNumber',
               'serial',
               required: true
            ),
         ],
         collectionPath: CollectionPath::root(),
         paginationConfig: PaginationConfig::none(),
         triggerType: $trigger,
         externalObjectType: 'device',
         outboundConfig: OutboundConfig::none(),
         webhookKey: $webhookKey
      );

      $job->assignId(new SyncJobId(1));

      if ($status === SyncJobStatus::ACTIVE) {
         $job->activate();
      } else if ($status === SyncJobStatus::PAUSED) {
         $job->activate();
         $job->pause();
      }

      $job->releaseEvents();

      return $job;
   }

   private function makeCommand(
      string $name = 'Updated synchronization',
      InteractionTrigger $trigger = InteractionTrigger::POLLING
   ): UpdateSyncJobCommand {
      return new UpdateSyncJobCommand(
         id: new SyncJobId(1),
         name: new SyncJobName($name),
         authConfigReference: new AuthConfigReference(1),
         direction: SyncDirection::INBOUND,
         resourcePath: new ResourcePath('/updated-resource'),
         targetItemtype: TargetItemtype::COMPUTER,
         matchField: new MatchField('serial'),
         externalIdField: new ExternalIdField('external_id'),
         fieldMappings: [
            new FieldMapping(
               'serial',
               'serial',
               required: true
            ),
         ],
         entityAssignmentStrategy: null,
         collectionPath: CollectionPath::root(),
         paginationConfig: PaginationConfig::none(),
         triggerType: $trigger,
         externalObjectType: 'updated-device',
         outboundConfig: OutboundConfig::none()
      );
   }

   private function executeSuccessfully(
      SyncJob $job,
      UpdateSyncJobCommand $command
   ): SyncJob {
      $repository = $this->createMock(
         SyncJobRepositoryInterface::class
      );

      $repository->method('findById')->willReturn($job);
      $repository
         ->method('save')
         ->willReturnCallback(
            static fn (SyncJob $saved): SyncJob => $saved
         );

      $transaction = $this->createMock(
         TransactionManagerInterface::class
      );

      return $this->makeService(
         $repository,
         $this->authConfigRepositoryWith($this->makeAuthConfig()),
         $transaction
      )->execute($command);
   }
}
