<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\InteractionLookupRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;

/**
 * Generic GLPI-event outbound runtime. It knows HTTP definitions and GLPI fields,
 * but no vendor name. Connector-specific paths/bodies live only in JobTemplate.
 *
 * Every attempted outbound interaction gets a SyncExecution record so polling,
 * webhook and GLPI-event interactions share the same supervision history.
 */
final class DispatchGlpiItemEventService
{
    public function __construct(
        private readonly InteractionLookupRepositoryInterface $syncJobRepository,
        private readonly AuthConfigRepositoryInterface $authConfigRepository,
        private readonly ObjectLinkRepositoryInterface $objectLinkRepository,
        private readonly HttpEngineInterface $httpEngine,
        private readonly JsonPathValueReader $jsonPathReader,
        private readonly OutboundRuntimeValueResolver $resolver,
        private readonly SyncExecutionRepositoryInterface $executionRepository,
        private readonly TransactionManagerInterface $transaction,
        private readonly EventDispatcherInterface $events
    ) {
    }

    /**
    * @param array<string, mixed> $fields
    *
    * @return array{
    *     sent: int,
    *     failed: int,
    *     errors: list<string>
    * }
    */
    public function execute(TargetItemtype $itemtype, int $itemId, array $fields): array
    {
        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->syncJobRepository->findActiveForGlpiItem($itemtype->value) as $job) {
            $execution = null;

            try {
                if (!$this->isInEntityScope($job->entityAssignmentStrategy(), $fields)) {
                    continue;
                }

                $jobId = $job->id();
                if ($jobId === null) {
                    throw new \RuntimeException('Outbound interaction is not persisted.');
                }

                $connection = $this->authConfigRepository->findById(new AuthConfigId(
                    $job->authConfigReference()->value()
                ));
                if ($connection !== null && !$connection->isUsable()) {
                    // Disabling a Connection suspends its interactions without
                    // converting them into failures or changing their own state.
                    continue;
                }

                // Persist RUNNING before contacting the external system. This makes
                // missing-connection/auth/HTTP failures visible in the normal history view.
                $execution = $this->saveExecution(SyncExecution::start($jobId));
                if ($connection === null) {
                    throw new \RuntimeException('Connection is missing.');
                }

                $link = $this->objectLinkRepository->findByGlpiItem(
                    $job->authConfigReference(),
                    $itemtype,
                    $itemId,
                    $job->externalObjectType(),
                );
                $config = $job->outboundConfig();
                $requestDefinition = $link !== null ? $config->updateRequest : $config->createRequest;
                if ($requestDefinition === null) {
                    throw new \RuntimeException($link !== null
                      ? 'This outbound interaction has no update request definition.'
                      : 'This outbound interaction has no create request definition.');
                }

                $path = $this->resolver->resolvePath(
                    (string) ($requestDefinition['path'] ?? ''),
                    $fields,
                    $itemId,
                    $link?->externalId()->value(),
                );
                if ($path === '') {
                    throw new \RuntimeException('Outbound request path must not be empty.');
                }

                $bodyDefinition = $requestDefinition['body'] ?? null;
                $body = $bodyDefinition === null ? null : json_encode(
                    $this->resolver->resolveBody($bodyDefinition, $fields),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
                $method = HttpMethod::from(strtoupper((string) ($requestDefinition['method'] ?? 'POST')));
                $headers = ['Accept' => 'application/json'];
                if ($body !== null) {
                    $headers['Content-Type'] = 'application/json';
                }

                $response = $this->httpEngine->execute($connection, new HttpRequest(
                    $method,
                    $path,
                    $headers,
                    [],
                    $body
                ));
                if (!$response->isSuccessful()) {
                    throw new \RuntimeException(sprintf('External system returned HTTP %d.', $response->statusCode));
                }

                if ($link === null) {
                    $decoded = $response->json();
                    $rawExternalId = $this->jsonPathReader->read($decoded, $config->responseExternalIdPath);
                    if ($rawExternalId === null || $rawExternalId === '') {
                        throw new \RuntimeException('Outbound create succeeded but response external ID is missing.');
                    }
                    $this->objectLinkRepository->save(ObjectLink::create(
                        $jobId,
                        $job->authConfigReference(),
                        new ExternalId((string) $rawExternalId),
                        $itemtype,
                        $itemId,
                        $job->externalObjectType(),
                    ));
                } else {
                    $link->touch();
                    $this->objectLinkRepository->save($link);
                }

                $execution->complete(1, 0);
                $this->saveFinishedExecution($execution);
                $sent++;
            } catch (\Throwable $exception) {
                $failed++;
                $errors[] = sprintf('%s: %s', $job->name()->value(), $exception->getMessage());

                if ($execution !== null && !$execution->status()->isFinished()) {
                    try {
                        $execution->fail($exception->getMessage());
                        $this->saveFinishedExecution($execution);
                    } catch (\Throwable $historyException) {
                        $errors[] = sprintf(
                            '%s: outbound history persistence failed: %s',
                            $job->name()->value(),
                            $historyException->getMessage(),
                        );
                    }
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }

    private function saveExecution(SyncExecution $execution): SyncExecution
    {
        $this->transaction->begin();
        try {
            $saved = $this->executionRepository->save($execution);
            $this->transaction->commit();
            return $saved;
        } catch (\Throwable $exception) {
            $this->transaction->rollback();
            throw $exception;
        }
    }

    private function saveFinishedExecution(SyncExecution $execution): void
    {
        $this->transaction->begin();
        try {
            $this->executionRepository->save($execution);
            $this->transaction->commit();
        } catch (\Throwable $exception) {
            $this->transaction->rollback();
            throw $exception;
        }

        foreach ($execution->releaseEvents() as $event) {
            $this->events->dispatch($event);
        }
    }

    /** @param array<string,mixed> $fields */
    private function isInEntityScope(
        ?\GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy $strategy,
        array $fields
    ): bool {
        if ($strategy === null) {
            return true;
        }
        if (!$strategy->isFixed()) {
            // MAPPED is an inbound routing concept. Outbound templates use a
            // fixed entity as an explicit source scope.
            return false;
        }
        return isset($fields['entities_id']) && (int) $fields['entities_id'] === $strategy->fixedEntityId();
    }
}
