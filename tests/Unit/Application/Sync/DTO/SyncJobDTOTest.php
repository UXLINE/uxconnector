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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\DTO;

use GlpiPlugin\Uxconnector\Application\Sync\DTO\SyncJobDTO;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncJobDTO::class)]
final class SyncJobDTOTest extends TestCase
{
   public function testFromEntityMapsEveryField(): void {
       $syncJob = SyncJob::create(
           new SyncJobName('MDM inventory sync'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [
               new FieldMapping('serialNumber', 'serial', required: true),
               new FieldMapping('mail', 'email1', required: false),
           ]
       );
       $syncJob->assignId(new SyncJobId(7));
       $syncJob->activate();

       $dto = SyncJobDTO::fromEntity($syncJob);

       self::assertSame(7, $dto->id);
       self::assertSame('MDM inventory sync', $dto->name);
       self::assertSame('1', $dto->authConfigReference);
       self::assertSame('inbound', $dto->direction);
       self::assertSame('/computers', $dto->resourcePath);
       self::assertSame('computer', $dto->targetItemtype);
       self::assertSame('serial', $dto->matchField);
       self::assertSame('id', $dto->externalIdField);
       self::assertSame(2, $dto->fieldMappingsCount);
       self::assertSame('Aucune (comportement GLPI par défaut)', $dto->entityAssignmentSummary);
       self::assertSame('active', $dto->status);
       self::assertTrue($dto->isRunnable);
   }

   public function testFromEntitySummarizesAFixedEntityStrategy(): void {
       $syncJob = SyncJob::create(
           new SyncJobName('MDM inventory sync'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [new FieldMapping('serialNumber', 'serial', required: true)],
           \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy::fixed(42)
       );
       $syncJob->assignId(new SyncJobId(1));

       $dto = SyncJobDTO::fromEntity($syncJob);

       self::assertSame('Fixe — entité #42', $dto->entityAssignmentSummary);
   }

   public function testFromEntitySummarizesAMappedEntityStrategy(): void {
       $syncJob = SyncJob::create(
           new SyncJobName('MDM inventory sync'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [new FieldMapping('serialNumber', 'serial', required: true)],
           \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy::mapped(
               'location',
               ['Nantes' => 42, 'Paris' => 68]
           )
       );
       $syncJob->assignId(new SyncJobId(1));

       $dto = SyncJobDTO::fromEntity($syncJob);

       self::assertSame('Déduite de « location » (2 règle(s))', $dto->entityAssignmentSummary);
   }

   public function testFromEntityRejectsAggregateWithoutId(): void {
       $syncJob = SyncJob::create(
           new SyncJobName('MDM inventory sync'),
           new AuthConfigReference(1),
           SyncDirection::INBOUND,
           new ResourcePath('/computers'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id')
       );

       $this->expectException(\LogicException::class);

       SyncJobDTO::fromEntity($syncJob);
   }
}
