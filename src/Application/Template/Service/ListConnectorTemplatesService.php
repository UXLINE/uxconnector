<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Template\Service;

use GlpiPlugin\Uxconnector\Domain\Template\Repository\ConnectorTemplateRepositoryInterface;

final class ListConnectorTemplatesService
{
    public function __construct(private readonly ConnectorTemplateRepositoryInterface $repository) {}

    /** @return list<array<string,mixed>> */
    public function execute(): array
    {
        return array_map(static fn($connector): array => [
            'id' => $connector->id,
            'version' => $connector->version,
            'name' => $connector->name,
            'description' => $connector->description,
            'icon' => $connector->icon,
            'endpointHint' => $connector->endpointHint,
            'testPathHint' => $connector->testPathHint,
            'authMethods' => $connector->authMethods,
            'jobCount' => count(array_filter($connector->jobs, static fn($job): bool => $job->available && !$job->retired)),
            'plannedCount' => count(array_filter($connector->jobs, static fn($job): bool => !$job->available && !$job->retired)),
        ], $this->repository->findAll());
    }
}
