<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Template\Entity;

final readonly class ConnectorTemplate
{
    /**
     * @param list<string> $authMethods
     * @param list<JobTemplate> $jobs
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $name,
        public string $description,
        public string $icon,
        public string $endpointHint,
        public string $testPathHint,
        public array $authMethods,
        public array $jobs
    ) {
        if (trim($id) === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid connector template id "%s".', $id));
        }
        if (trim($version) === '' || trim($name) === '') {
            throw new \InvalidArgumentException('Connector template version/name must not be empty.');
        }
    }

    public function findJob(string $jobId): ?JobTemplate
    {
        foreach ($this->jobs as $job) {
            if ($job->id === $jobId) {
                return $job;
            }
        }
        return null;
    }
}
