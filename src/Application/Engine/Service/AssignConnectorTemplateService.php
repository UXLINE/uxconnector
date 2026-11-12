<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Template\Repository\ConnectorTemplateRepositoryInterface;

/** Associates an existing connection with a connector definition from the catalogue. */
final class AssignConnectorTemplateService
{
    public function __construct(
        private readonly AuthConfigRepositoryInterface $repository,
        private readonly ConnectorTemplateRepositoryInterface $templates
    ) {
    }

    public function execute(AuthConfigId $id, string $connectorId): void
    {
        $connection = $this->repository->findById($id);
        if ($connection === null) {
            throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::forId($id);
        }

        $connectorId = strtolower(trim($connectorId));
        $connector = null;

        if ($connectorId !== 'custom') {
            $connector = $this->templates->findById($connectorId);
            if ($connector === null) {
                throw new \RuntimeException(sprintf('Unknown connector type "%s".', $connectorId));
            }

            $authMethod = $connection->authMethod()->value;
            if (!in_array($authMethod, $connector->authMethods, true)) {
                throw new \RuntimeException(sprintf(
                    'Authentication method "%s" is not supported by connector "%s".',
                    $authMethod,
                    $connector->name,
                ));
            }
        }

        $connection->changeConnectorId($connectorId);

        // A standard connector owns the recommended health-check resource.
        // Associating a legacy/custom connection therefore also adopts that
        // resource so the next Test action validates a real authenticated API
        // endpoint instead of the generic root path (which commonly returns
        // 401/404 on vendor gateways such as Jira Cloud).
        if ($connector !== null) {
            $connection->changeTestPath(new TestPath($connector->testPathHint));
        }

        $this->repository->save($connection);
    }
}
