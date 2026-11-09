<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Template\Service;

use GlpiPlugin\Uxconnector\Domain\Template\Repository\ConnectorTemplateRepositoryInterface;

final class GetConnectorTemplateService
{
    public function __construct(private readonly ConnectorTemplateRepositoryInterface $repository) {}

    /** @return array<string,mixed> */
    public function execute(string $id): array
    {
        $connector = $this->repository->findById($id);
        if ($connector === null) {
            throw new \RuntimeException(sprintf('Unknown connector template "%s".', $id));
        }

        return [
            'id' => $connector->id,
            'version' => $connector->version,
            'name' => $connector->name,
            'description' => $connector->description,
            'icon' => $connector->icon,
            'endpointHint' => $connector->endpointHint,
            'testPathHint' => $connector->testPathHint,
            'authMethods' => $connector->authMethods,
            'jobs' => array_map(static fn($job): array => [
                'id' => $job->id,
                'version' => $job->version,
                'name' => $job->name,
                'description' => $job->description,
                'targetItemtype' => $job->targetItemtype,
                'direction' => $job->direction,
                'triggerType' => $job->triggerType,
                'externalObjectType' => $job->externalObjectType,
                'available' => $job->available,
                'retired' => $job->retired,
                'parameters' => array_map(static fn($parameter): array => [
                    'name' => $parameter->name,
                    'label' => $parameter->label,
                    'required' => $parameter->required,
                    'default' => $parameter->default,
                    'description' => $parameter->description,
                ], $job->parameters),
            ], $connector->jobs),
        ];
    }
}
