<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Template;

use GlpiPlugin\Uxconnector\Application\Template\Service\GetConnectorTemplateService;
use GlpiPlugin\Uxconnector\Application\Template\Service\InstantiateJobTemplateService;
use GlpiPlugin\Uxconnector\Application\Template\Service\ListConnectorTemplatesService;
use GlpiPlugin\Uxconnector\Application\Template\Service\TemplateStringResolver;
use GlpiPlugin\Uxconnector\Domain\Template\Repository\ConnectorTemplateRepositoryInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;

final class TemplateServiceProvider
{
    public static function repository(): ConnectorTemplateRepositoryInterface
    {
        return new JsonConnectorTemplateRepository(dirname(__DIR__, 3) . '/resources/connectors');
    }

    public static function listService(): ListConnectorTemplatesService
    {
        return new ListConnectorTemplatesService(self::repository());
    }

    public static function getService(): GetConnectorTemplateService
    {
        return new GetConnectorTemplateService(self::repository());
    }

    public static function instantiateService(): InstantiateJobTemplateService
    {
        return new InstantiateJobTemplateService(
            self::repository(),
            SyncServiceProvider::createSyncJobService(),
            SyncServiceProvider::activateSyncJobService(),
            EngineServiceProvider::authConfigRepository(),
            new TemplateStringResolver(),
        );
    }
}
