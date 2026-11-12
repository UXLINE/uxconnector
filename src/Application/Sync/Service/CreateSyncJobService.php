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

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Command\CreateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncDirectionNotSupportedException;
use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\BuiltinTemplateTargetPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;

/**
 * Orchestrates the "create a SyncJob" use case.
 *
 * Sequence: validate the AuthConfigReference resolves to a real
 * AuthConfig (Engine) → begin → create aggregate → capture events →
 * persist → commit → dispatch events. Same event-capture discipline
 * as CreateAuthConfigService (see its class doc-block) : events are
 * read off the freshly created aggregate *before* the Repository's
 * save() reloads it, since SyncJob::reconstitute() never records
 * events.
 *
 * RULE-032/033 (adapted) : the referential-integrity check this
 * Service performs is exactly the one promised in
 * sql/install/install-2.0.0.php's migration comment - there is no
 * SQL foreign key between syncjobs and authconfigs (different
 * bounded contexts), so the check happens here instead.
 */
final class CreateSyncJobService
{
    public function __construct(
        private readonly SyncJobRepositoryInterface $syncJobRepository,
        private readonly AuthConfigRepositoryInterface $authConfigRepository,
        private readonly TargetFieldPolicy $targetFieldPolicy,
        private readonly TransactionManagerInterface $transaction,
        private readonly EventDispatcherInterface $events,
        private readonly ?JobEntityAccess $entityAccess = null
    ) {
    }

    /**
     * @throws SyncDirectionNotSupportedException if the command's direction
     *         is not yet implemented by ExecuteSyncJobService.
     * @throws SyncJobAuthConfigNotFoundException if the command's
     *         AuthConfigReference does not resolve to an existing AuthConfig.
     */
    public function execute(CreateSyncJobCommand $command): SyncJob
    {
        if (!$command->direction->isImplemented()) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncDirectionNotSupportedException::forDirection(
                $command->direction
            );
        }

        $this->entityAccess?->assertStrategy($command->entityAssignmentStrategy);
        $this->assertAuthConfigExists($command->authConfigReference->value());
        (new BuiltinTemplateTargetPolicy())->assertAllowed($command->templateId, $command->targetItemtype);
        (new OutboundRuntimeValueResolver())->assertConfiguration($command->outboundConfig);
        if ($command->direction->readsFromExternalSystem()) {
            $this->targetFieldPolicy->assertConfiguration(
                $command->targetItemtype,
                $command->matchField,
                $command->fieldMappings,
            );
        }

        $this->transaction->begin();

        try {
            $syncJob = SyncJob::create(
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
                $command->templateId,
                $command->templateVersion,
                $command->triggerType,
                $command->externalObjectType,
                $command->outboundConfig,
                $command->webhookKey,
            );

            $events = $syncJob->releaseEvents();

            \GlpiPlugin\Uxconnector\Domain\Sync\Policy\JobSchedule::fromJob($syncJob);
            $syncJob = $this->syncJobRepository->save($syncJob);

            $this->transaction->commit();

            foreach ($events as $event) {
                $this->events->dispatch($event);
            }

            return $syncJob;
        } catch (\Throwable $exception) {
            $this->transaction->rollback();

            throw $exception;
        }
    }

    private function assertAuthConfigExists(int $authConfigId): void
    {
        if (!$this->authConfigRepository->exists(new AuthConfigId($authConfigId))) {
            throw \GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobAuthConfigNotFoundException::forReference(
                new AuthConfigReference($authConfigId),
            );
        }
    }
}
