<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Bootstrap;

use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Template\TemplateServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Connection\Controller\ConnectionController;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\OAuth2ClientCredentialsSecretComposer;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\OAuth2PasswordSecretComposer;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\GlpiRestV1SecretComposer;

final class ConnectionControllerFactory
{
    public static function create(): ConnectionController
    {
        return new ConnectionController(
            EngineServiceProvider::listAuthConfigsService(),
            EngineServiceProvider::getAuthConfigService(),
            EngineServiceProvider::createAuthConfigService(),
            EngineServiceProvider::updateAuthConfigService(),
            EngineServiceProvider::testAuthConfigService(),
            EngineServiceProvider::activateAuthConfigService(),
            EngineServiceProvider::disableAuthConfigService(),
            EngineServiceProvider::deleteAuthConfigService(),
            EngineServiceProvider::assignConnectorTemplateService(),
            TemplateServiceProvider::listService(),
            TemplateServiceProvider::getService(),
            TemplateServiceProvider::instantiateService(),
            SyncServiceProvider::listSyncJobsService(),
            SyncServiceProvider::activateSyncJobService(),
            SyncServiceProvider::pauseSyncJobService(),
            SyncServiceProvider::queueSyncJobService(),
            SyncServiceProvider::deleteSyncJobService(),
            SyncServiceProvider::queueFullReplayService(),
            new OAuth2ClientCredentialsSecretComposer(),
            new OAuth2PasswordSecretComposer(),
            new GlpiRestV1SecretComposer(),
        );
    }
}
