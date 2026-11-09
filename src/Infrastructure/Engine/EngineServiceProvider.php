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

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine;

use GlpiPlugin\Uxconnector\Application\Engine\Service\ActivateAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\AssignConnectorTemplateService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\CreateAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\DeleteAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\DisableAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\GetAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\ListAuthConfigsService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\TestAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\UpdateAuthConfigService;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Connection\GlpiConnectionArtifactsCleaner;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\AuthenticationStrategyFactory;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\OAuth2TokenClient;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\GenericHttpEngine;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security\DestinationPolicy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security\DnsHostResolver;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security\HostResolverInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigPersistenceMapper;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigSecretStore;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigUsageChecker;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Security\OpenSslCredentialVault;
use GlpiPlugin\Uxconnector\Infrastructure\Shared\Event\SynchronousEventDispatcher;
use GlpiPlugin\Uxconnector\Infrastructure\Shared\Persistence\GlpiTransactionManager;
use GlpiPlugin\Uxconnector\Infrastructure\Template\TemplateServiceProvider;

/**
 * Single wiring point for the Engine bounded context's dependency
 * graph, shared by every entry point that needs it
 * (front/authconfig.php, front/authconfig.form.php,
 * SyncJobCronTask, and any future one).
 *
 * This is exactly the centralization promised in front/authconfig.php's
 * original comment ("Ce câblage sera centralisé dès qu'un deuxième
 * front/*.php en aura également besoin.") - a second call site
 * (front/authconfig.form.php) now exists, so the poor man's DI is
 * replaced by this provider rather than duplicated.
 *
 * Deliberately not a full DI container: each method simply builds
 * and returns a fresh, correctly-wired instance. GLPI's request
 * lifecycle is one PHP process per HTTP request, so there is no
 * shared-instance/singleton concern to solve here.
 */
final class EngineServiceProvider
{
    public static function authConfigRepository(): AuthConfigRepositoryInterface
    {
        return new AuthConfigRepository(
            new AuthConfigPersistenceMapper(),
            self::authConfigSecretStore(),
        );
    }

    public static function authConfigSecretStore(): AuthConfigSecretStoreInterface
    {
        return new AuthConfigSecretStore();
    }

    public static function credentialVault(): CredentialVaultInterface
    {
        return new OpenSslCredentialVault();
    }

    public static function hostResolver(): HostResolverInterface
    {
        return new DnsHostResolver();
    }

    /**
     * CORRECTIF SÉCURITÉ (SSRF, revue de code externe) : source unique
     * de la politique de destination, partagée par GenericHttpEngine
     * (vérification à chaque exécution) et CreateAuthConfigService
     * (rejet immédiat à la création).
     */
    public static function destinationPolicy(): DestinationPolicyInterface
    {
        return new DestinationPolicy(self::hostResolver());
    }

    public static function oauth2TokenClient(): OAuth2TokenClient
    {
        return new OAuth2TokenClient(self::destinationPolicy());
    }

    public static function httpEngine(): HttpEngineInterface
    {
        return new GenericHttpEngine(
            self::authConfigSecretStore(),
            self::credentialVault(),
            new AuthenticationStrategyFactory(self::oauth2TokenClient(), self::destinationPolicy()),
            self::destinationPolicy(),
        );
    }

    public static function listAuthConfigsService(): ListAuthConfigsService
    {
        return new ListAuthConfigsService(self::authConfigRepository());
    }

    public static function getAuthConfigService(): GetAuthConfigService
    {
        return new GetAuthConfigService(self::authConfigRepository());
    }

    public static function assignConnectorTemplateService(): AssignConnectorTemplateService
    {
        return new AssignConnectorTemplateService(
            self::authConfigRepository(),
            TemplateServiceProvider::repository(),
        );
    }

    public static function createAuthConfigService(): CreateAuthConfigService
    {
        return new CreateAuthConfigService(
            self::authConfigRepository(),
            self::authConfigSecretStore(),
            self::credentialVault(),
            self::destinationPolicy(),
            new GlpiTransactionManager($GLOBALS['DB']),
            new SynchronousEventDispatcher(),
        );
    }

    public static function updateAuthConfigService(): UpdateAuthConfigService
    {
        return new UpdateAuthConfigService(
            self::authConfigRepository(),
            self::authConfigSecretStore(),
            self::credentialVault(),
            self::destinationPolicy(),
            new GlpiTransactionManager($GLOBALS['DB']),
        );
    }

    /**
     * CYCLE D'ACTIVATION (issu des deux relectures externes).
     */
    public static function testAuthConfigService(): TestAuthConfigService
    {
        return new TestAuthConfigService(
            self::authConfigRepository(),
            self::httpEngine(),
        );
    }

    public static function activateAuthConfigService(): ActivateAuthConfigService
    {
        return new ActivateAuthConfigService(self::authConfigRepository());
    }

    public static function disableAuthConfigService(): DisableAuthConfigService
    {
        return new DisableAuthConfigService(self::authConfigRepository());
    }

    public static function deleteAuthConfigService(): DeleteAuthConfigService
    {
        return new DeleteAuthConfigService(
            self::authConfigRepository(),
            new AuthConfigUsageChecker($GLOBALS['DB']),
            new GlpiTransactionManager($GLOBALS['DB']),
            new GlpiConnectionArtifactsCleaner(),
        );
    }

}
