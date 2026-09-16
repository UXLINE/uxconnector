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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Sync\Entity;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Event\SyncJobCreatedEvent;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncJob::class)]
final class SyncJobTest extends TestCase
{
   private function makeName(): SyncJobName {
       return new SyncJobName('MDM inventory sync');
   }

   private function makeMapping(): FieldMapping {
       return new FieldMapping('serialNumber', 'serial', required: true);
   }

   private function makeResourcePath(): ResourcePath {
       return new ResourcePath('/computers');
   }

   private function makeTargetItemtype(): TargetItemtype {
       return TargetItemtype::COMPUTER;
   }

   private function makeMatchField(): MatchField {
       return new MatchField('serial');
   }

   private function makeExternalIdField(): ExternalIdField {
       return new ExternalIdField('id');
   }

   public function testCreateStartsInDraftWithoutIdentifier(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField()
       );

       self::assertNull($syncJob->id());
       self::assertSame(SyncJobStatus::DRAFT, $syncJob->status());
       self::assertFalse($syncJob->isRunnable());
       self::assertSame([], $syncJob->fieldMappings());
   }

   public function testCreateRecordsSyncJobCreatedEvent(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField()
       );

       $events = $syncJob->releaseEvents();

       self::assertCount(1, $events);
       self::assertInstanceOf(SyncJobCreatedEvent::class, $events[0]);
       self::assertSame('sync.sync_job.created', $events[0]->name());
   }

   public function testActivateFailsWithoutFieldMappings(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField()
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::class);

       $syncJob->activate();
   }

   public function testActivateSucceedsWithAtLeastOneFieldMapping(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField(),
           [$this->makeMapping()]
       );

       $syncJob->activate();

       self::assertSame(SyncJobStatus::ACTIVE, $syncJob->status());
       self::assertTrue($syncJob->isRunnable());
   }

   public function testActivateFailsFromErrorStatus(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField(),
           [$this->makeMapping()]
       );
       $syncJob->activate();
       $syncJob->markError();

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::class);

       $syncJob->activate();
   }

   public function testPausedJobCanBeReactivated(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField(),
           [$this->makeMapping()]
       );
       $syncJob->activate();
       $syncJob->pause();

       self::assertSame(SyncJobStatus::PAUSED, $syncJob->status());

       $syncJob->activate();

       self::assertSame(SyncJobStatus::ACTIVE, $syncJob->status());
   }

   public function testChangeFieldMappingsReplacesTheWholeList(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField(),
           [$this->makeMapping()]
       );

       $newMapping = new FieldMapping('alternateSerialNumber', 'serial');
       $syncJob->changeFieldMappings([$newMapping]);

       self::assertCount(1, $syncJob->fieldMappings());
       self::assertTrue($syncJob->fieldMappings()[0]->equals($newMapping));
   }

   public function testAssignIdCannotBeCalledTwice(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField()
       );
       $syncJob->assignId(new SyncJobId(1));

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::class);

       $syncJob->assignId(new SyncJobId(2));
   }

   public function testReconstituteDoesNotRecordAnyEvent(): void {
       $syncJob = SyncJob::reconstitute(
           new SyncJobId(1),
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::BIDIRECTIONAL,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField(),
           [$this->makeMapping()],
           SyncJobStatus::ACTIVE,
           new \DateTimeImmutable('2026-01-01'),
           new \DateTimeImmutable('2026-01-02')
       );

       self::assertSame([], $syncJob->releaseEvents());
       self::assertSame(1, $syncJob->id()?->value());
   }

   public function testCreateRejectsAMatchFieldNotProducedByAnyFieldMapping(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::class);

       SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           new MatchField('doesNotExist'),
           $this->makeExternalIdField(),
           [$this->makeMapping()] // targetField is "serial", not "doesNotExist"
       );
   }

   public function testCreateAllowsAnUnmatchedMatchFieldWhenThereAreNoFieldMappingsYet(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           new MatchField('not-mapped-yet'),
           $this->makeExternalIdField()
       );

       self::assertSame('not-mapped-yet', $syncJob->matchField()->value());
   }

   public function testChangeFieldMappingsRejectsALaterInconsistentMatchField(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField(),
           [$this->makeMapping()] // targetField "serial", matches makeMatchField()
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::class);

       $syncJob->changeFieldMappings([new FieldMapping('mail', 'email1')]);
   }

   public function testChangeMatchFieldRejectsAValueNotProducedByAnyFieldMapping(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField(),
           [$this->makeMapping()]
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::class);

       $syncJob->changeMatchField(new MatchField('unknownField'));
   }


   public function testCreateAllowsAssetIdentityWhenAnIdentityFieldIsMapped(): void {
      $syncJob = SyncJob::create(
         $this->makeName(),
         new AuthConfigReference(1),
         SyncDirection::INBOUND,
         $this->makeResourcePath(),
         TargetItemtype::COMPUTER,
         new MatchField('asset_identity'),
         $this->makeExternalIdField(),
         [new FieldMapping('serialNumber', 'serial')]
      );

      self::assertSame('asset_identity', $syncJob->matchField()->value());
   }

   public function testCreateRejectsAssetIdentityWithoutAnyMappedIdentityField(): void {
      $this->expectException(\GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException::class);
      $this->expectExceptionMessage('requires at least one mapped identity field');

      SyncJob::create(
         $this->makeName(),
         new AuthConfigReference(1),
         SyncDirection::INBOUND,
         $this->makeResourcePath(),
         TargetItemtype::COMPUTER,
         new MatchField('asset_identity'),
         $this->makeExternalIdField(),
         [new FieldMapping('comment', 'comment')]
      );
   }

   public function testExternalIdFieldIsExposedByItsGetter(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           new ExternalIdField('sys_id')
       );

       self::assertSame('sys_id', $syncJob->externalIdField()->value());
   }

   public function testChangeExternalIdFieldReplacesTheValue(): void {
       $syncJob = SyncJob::create(
           $this->makeName(),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           $this->makeResourcePath(),
           $this->makeTargetItemtype(),
           $this->makeMatchField(),
           $this->makeExternalIdField()
       );

       $syncJob->changeExternalIdField(new ExternalIdField('external_ref'));

       self::assertSame('external_ref', $syncJob->externalIdField()->value());
   }
}
