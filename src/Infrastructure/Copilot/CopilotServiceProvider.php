<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot;

use GlpiPlugin\Uxconnector\Application\Copilot\Analytics\AnalyticsService;
use GlpiPlugin\Uxconnector\Application\Copilot\Service\CreateCopilotClientService;
use GlpiPlugin\Uxconnector\Application\Copilot\Service\DeleteCopilotClientService;
use GlpiPlugin\Uxconnector\Application\Copilot\Service\GetTicketContextService;
use GlpiPlugin\Uxconnector\Application\Copilot\Service\ListCopilotClientsService;
use GlpiPlugin\Uxconnector\Application\Copilot\Service\SearchTicketsService;
use GlpiPlugin\Uxconnector\Application\Copilot\Service\SetCopilotClientActiveService;
use GlpiPlugin\Uxconnector\Domain\Copilot\Port\ItsmDataProviderInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Persistence\CopilotClientRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Provider\GlpiHlApiProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Provider\LocalGlpiAnalyticsProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Provider\LocalGlpiProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;

final class CopilotServiceProvider
{
    /**
     * @param array<string, mixed> $client
     */
    public static function providerForClient(array $client): ItsmDataProviderInterface
    {
        $providerType = (string) ($client['provider_type'] ?? 'glpi_hl_api');
        if ($providerType === 'local_glpi') {
            return new LocalGlpiProvider();
        }
        if ($providerType !== 'glpi_hl_api') {
            throw new \RuntimeException('Copilot client references an unsupported ITSM provider.');
        }

        $id = (int) ($client['auth_config_reference'] ?? 0);
        $connection = $id > 0
            ? EngineServiceProvider::authConfigRepository()->findById(new AuthConfigId($id))
            : null;
        if ($connection === null) {
            throw new \RuntimeException('Copilot client references an unknown GLPI HL connection.');
        }
        return new GlpiHlApiProvider($connection, EngineServiceProvider::httpEngine());
    }

    /**
     * @param array<string, mixed> $client
     */
    public static function searchTicketsService(array $client): SearchTicketsService
    {
        return new SearchTicketsService(self::providerForClient($client));
    }

    /**
     * @param array<string, mixed> $client
     */
    public static function getTicketContextService(array $client): GetTicketContextService
    {
        return new GetTicketContextService(self::providerForClient($client));
    }


    /**
     * @param array<string, mixed> $client
     */
    public static function analyticsService(array $client): AnalyticsService
    {
        $providerType = (string) ($client['provider_type'] ?? 'glpi_hl_api');
        if ($providerType !== 'local_glpi') {
            throw new \RuntimeException('Lot 2 analytics is currently available for the Local GLPI provider only.');
        }
        return new AnalyticsService(new LocalGlpiAnalyticsProvider());
    }

    public static function listClientsService(): ListCopilotClientsService
    {
        return new ListCopilotClientsService(new CopilotClientRepository());
    }

    public static function createClientService(): CreateCopilotClientService
    {
        return new CreateCopilotClientService(new CopilotClientRepository());
    }

    public static function setClientActiveService(): SetCopilotClientActiveService
    {
        return new SetCopilotClientActiveService(new CopilotClientRepository());
    }

    public static function deleteClientService(): DeleteCopilotClientService
    {
        return new DeleteCopilotClientService(new CopilotClientRepository());
    }
}
