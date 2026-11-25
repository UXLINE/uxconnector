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

namespace GlpiPlugin\Uxconnector\Presentation\Engine\Controller;

use GlpiPlugin\Uxconnector\Application\Engine\Command\ActivateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\CreateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\DeleteAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\DisableAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\TestAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Service\ActivateAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\CreateAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\DeleteAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\DisableAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\ListAuthConfigsService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\TestAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\JobEntityAccess;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Presentation\Controller\AbstractController;
use Session;

/**
 * Controller for connection screens. Delegates business operations to Application services
 * and renders DTOs. Value objects validate business invariants; the controller validates
 * request structure and handles errors without issuing SQL or third-party HTTP requests.
 */
final class AuthConfigController extends AbstractController
{
    public function __construct(
        private readonly ListAuthConfigsService $listAuthConfigs,
        private readonly CreateAuthConfigService $createAuthConfig,
        private readonly TestAuthConfigService $testAuthConfig,
        private readonly ActivateAuthConfigService $activateAuthConfig,
        private readonly DisableAuthConfigService $disableAuthConfig,
        private readonly DeleteAuthConfigService $deleteAuthConfig,
        private readonly OAuth2ClientCredentialsSecretComposer $oauth2SecretComposer,
        private readonly OAuth2PasswordSecretComposer $oauth2PasswordSecretComposer,
        private readonly GlpiRestV1SecretComposer $glpiRestV1SecretComposer,
        private readonly JobEntityAccess $jobEntityAccess
    ) {
    }

    /**
     * Displays every configured AuthConfig.
     */
    public function index(): void
    {
        $authConfigs = $this->listAuthConfigs->execute();

        $this->render('engine/authconfig/list.html.twig', [
            'authconfigs' => $authConfigs,
            'can_create'  => Session::haveRight('plugin_uxconnector_authconfig', CREATE),
            'can_delete'  => Session::haveRight('plugin_uxconnector_authconfig', PURGE),
        ]);
    }

    /**
     * Displays the creation form with only authentication methods implemented by the engine.
     * AuthMethod::isImplemented() is shared with server-side validation.
     */
    /** @param array<string,mixed> $defaults */
    public function showCreateForm(array $defaults = []): void
    {
        $authMethods = array_values(array_filter(
            AuthMethod::cases(),
            static fn (AuthMethod $method): bool => $method->isImplemented(),
        ));

        $requestedMethod = (string) ($defaults['auth_method'] ?? '');
        $selectedMethod = null;
        foreach ($authMethods as $method) {
            if ($method->value === $requestedMethod) {
                $selectedMethod = $requestedMethod;
                break;
            }
        }

        $this->render('engine/authconfig/form.html.twig', [
            'authMethods' => $authMethods,
            'defaults' => [
                'name' => trim((string) ($defaults['name'] ?? '')),
                'endpoint' => trim((string) ($defaults['endpoint'] ?? '')),
                'test_path' => trim((string) ($defaults['test_path'] ?? '/')) ?: '/',
                'auth_method' => $selectedMethod,
                'connector_id' => trim((string) ($defaults['connector'] ?? $defaults['connector_id'] ?? 'custom')) ?: 'custom',
            ],
        ]);
    }

    /**
     * Handles the creation form submission.
     *
     * @param array<string, mixed> $request Typically $_POST.
     */
    public function create(#[\SensitiveParameter] array $request): void
    {
        try {
            $authMethod = AuthMethod::from((string) ($request['auth_method'] ?? ''));

            $command = new CreateAuthConfigCommand(
                new AuthConfigName((string) ($request['name'] ?? '')),
                new Endpoint((string) ($request['endpoint'] ?? '')),
                $authMethod,
                $this->resolveSecret($authMethod, $request),
                new TestPath((string) ($request['test_path'] ?? '/')),
                (string) ($request['connector_id'] ?? 'custom'),
            );

            $this->createAuthConfig->execute($command);

            Session::addMessageAfterRedirect(
                __('Authentication configuration created.', 'uxconnector'),
            );

            $this->redirect('/plugins/uxconnector/front/authconfig.php');
        } catch (\Throwable $exception) {
            Session::addMessageAfterRedirect(htmlescape($exception->getMessage()), false, ERROR);

            $this->redirect('/plugins/uxconnector/front/authconfig.form.php');
        }
    }

    /**
     * OAuth2 Client Credentials needs four distinct pieces of data
     * (token URL, client ID, client secret, optional scope) rather
     * than the single generic secret every other AuthMethod uses -
     * composed here into the JSON payload
     * AuthenticationStrategyFactory expects (see its own doc-block).
     *
     * @param array<string, mixed> $request
     */
    private function resolveSecret(AuthMethod $authMethod, array $request): ?string
    {
        if ($authMethod === AuthMethod::OAUTH2_CLIENT_CREDENTIALS) {
            return $this->oauth2SecretComposer->compose($request);
        }
        if ($authMethod === AuthMethod::OAUTH2_PASSWORD) {
            return $this->oauth2PasswordSecretComposer->compose($request);
        }
        if ($authMethod === AuthMethod::GLPI_REST_V1) {
            return $this->glpiRestV1SecretComposer->compose($request);
        }

        $secret = trim((string) ($request['secret'] ?? ''));

        return $secret !== '' ? $secret : null;
    }

    /**
     * Handles connection tests through the activation lifecycle service.
     *
     * @param array<string, mixed> $request Typically $_POST.
     */
    public function test(#[\SensitiveParameter] array $request): void
    {
        try {
            $id = new AuthConfigId((int) ($request['id'] ?? 0));
            $this->jobEntityAccess->assertConnectionId($id);
            $result = $this->testAuthConfig->execute(new TestAuthConfigCommand($id));

            if ($result->success) {
                Session::addMessageAfterRedirect(sprintf(
                    __(
                        'Connection test succeeded (HTTP %d, %d ms). You may now activate this configuration.',
                        'uxconnector'
                    ),
                    $result->statusCode,
                    (int) round($result->durationMs ?? 0),
                ));
            } else {
                Session::addMessageAfterRedirect(
                    sprintf(__('Connection test failed: %s', 'uxconnector'), htmlescape($result->errorMessage)),
                    false,
                    ERROR,
                );
            }
        } catch (\Throwable $exception) {
            Session::addMessageAfterRedirect(htmlescape($exception->getMessage()), false, ERROR);
        }

        $this->redirect('/plugins/uxconnector/front/authconfig.php');
    }

    /**
     * @param array<string, mixed> $request Typically $_POST.
     */
    public function activate(#[\SensitiveParameter] array $request): void
    {
        try {
            $id = new AuthConfigId((int) ($request['id'] ?? 0));
            $this->jobEntityAccess->assertConnectionId($id);
            $this->activateAuthConfig->execute(new ActivateAuthConfigCommand($id));

            Session::addMessageAfterRedirect(__('Authentication configuration activated.', 'uxconnector'));
        } catch (\Throwable $exception) {
            Session::addMessageAfterRedirect(htmlescape($exception->getMessage()), false, ERROR);
        }

        $this->redirect('/plugins/uxconnector/front/authconfig.php');
    }

    /**
     * @param array<string, mixed> $request Typically $_POST.
     */
    public function disable(#[\SensitiveParameter] array $request): void
    {
        try {
            $id = new AuthConfigId((int) ($request['id'] ?? 0));
            $this->jobEntityAccess->assertConnectionId($id);
            $this->disableAuthConfig->execute(new DisableAuthConfigCommand($id));

            Session::addMessageAfterRedirect(__('Authentication configuration disabled.', 'uxconnector'));
        } catch (\Throwable $exception) {
            Session::addMessageAfterRedirect(htmlescape($exception->getMessage()), false, ERROR);
        }

        $this->redirect('/plugins/uxconnector/front/authconfig.php');
    }
    /**
     * Permanently deletes an authentication configuration after the
     * Application Service has verified that no SyncJob still references it.
     *
     * @param array<string, mixed> $request Typically $_POST.
     */
    public function delete(#[\SensitiveParameter] array $request): void
    {
        try {
            $id = new AuthConfigId((int) ($request['id'] ?? 0));
            $this->jobEntityAccess->assertConnectionId($id);
            $this->deleteAuthConfig->execute(new DeleteAuthConfigCommand($id));

            Session::addMessageAfterRedirect(__('Authentication configuration deleted.', 'uxconnector'));
        } catch (\Throwable $exception) {
            Session::addMessageAfterRedirect(htmlescape($exception->getMessage()), false, ERROR);
        }

        $this->redirect('/plugins/uxconnector/front/authconfig.php');
    }
}
