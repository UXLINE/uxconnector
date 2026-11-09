<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Connection\Controller;

use GlpiPlugin\Uxconnector\Application\Engine\Command\ActivateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\CreateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\DeleteAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\DisableAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\TestAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Command\UpdateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Service\ActivateAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\AssignConnectorTemplateService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\CreateAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\DeleteAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\DisableAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\GetAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\ListAuthConfigsService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\TestAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\UpdateAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Sync\Command\ActivateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Command\DeleteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Command\PauseSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ActivateSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\DeleteSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\QueueSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\QueueFullReplayService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ListSyncJobsService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\PauseSyncJobService;
use GlpiPlugin\Uxconnector\Application\Template\Service\GetConnectorTemplateService;
use GlpiPlugin\Uxconnector\Application\Template\Service\InstantiateJobTemplateService;
use GlpiPlugin\Uxconnector\Application\Template\Service\ListConnectorTemplatesService;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Presentation\Controller\AbstractController;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\OAuth2ClientCredentialsSecretComposer;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\OAuth2PasswordSecretComposer;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\GlpiRestV1SecretComposer;
use Session;

/** User-facing connection workflow. AuthConfig remains the internal aggregate. */
final class ConnectionController extends AbstractController
{
    public function __construct(
        private readonly ListAuthConfigsService $listConnections,
        private readonly GetAuthConfigService $getConnection,
        private readonly CreateAuthConfigService $createConnection,
        private readonly UpdateAuthConfigService $updateConnection,
        private readonly TestAuthConfigService $testConnection,
        private readonly ActivateAuthConfigService $activateConnection,
        private readonly DisableAuthConfigService $disableConnection,
        private readonly DeleteAuthConfigService $deleteConnection,
        private readonly AssignConnectorTemplateService $assignConnector,
        private readonly ListConnectorTemplatesService $listConnectorTemplates,
        private readonly GetConnectorTemplateService $getConnectorTemplate,
        private readonly InstantiateJobTemplateService $instantiateJobTemplate,
        private readonly ListSyncJobsService $listJobs,
        private readonly ActivateSyncJobService $activateJob,
        private readonly PauseSyncJobService $pauseJob,
        private readonly QueueSyncJobService $queueJob,
        private readonly DeleteSyncJobService $deleteJob,
        private readonly QueueFullReplayService $queueFullReplay,
        private readonly OAuth2ClientCredentialsSecretComposer $oauth2SecretComposer,
        private readonly OAuth2PasswordSecretComposer $oauth2PasswordSecretComposer,
        private readonly GlpiRestV1SecretComposer $glpiRestV1SecretComposer
    ) {}

    public function index(): void
    {
        $connectorNames = ['custom' => __('Custom REST', 'uxconnector')];
        foreach ($this->listConnectorTemplates->execute() as $connector) {
            $connectorNames[$connector['id']] = $connector['name'];
        }

        $this->render('connection/list.html.twig', [
            'connections' => $this->listConnections->execute(),
            'connector_names' => $connectorNames,
            'can_create' => Session::haveRight('plugin_uxconnector_authconfig', CREATE),
            'can_update' => Session::haveRight('plugin_uxconnector_authconfig', UPDATE),
            'can_delete' => Session::haveRight('plugin_uxconnector_authconfig', PURGE),
            'can_read_jobs' => Session::haveRight('plugin_uxconnector_syncjob', READ),
            'can_read_copilot' => Session::haveRight('plugin_uxconnector_authconfig', READ),
        ]);
    }

    /** @param array<string,mixed> $request */
    public function showCreateForm(array $request = []): void
    {
        $connectorId = strtolower(trim((string) ($request['connector'] ?? 'custom')));
        $connector = null;
        if ($connectorId !== 'custom') {
            try {
                $connector = $this->getConnectorTemplate->execute($connectorId);
            } catch (\Throwable) {
                $connectorId = 'custom';
            }
        }
        $authMethods = array_values(array_filter(
            AuthMethod::cases(),
            static fn(AuthMethod $m): bool => $m->isImplemented()
                 && ($connector === null || in_array($m->value, $connector['authMethods'], true)),
        ));
        $defaults = [
            'connector_id' => $connectorId,
            'name' => (string) ($request['name'] ?? ($connector ? $connector['name'] : '')),
            'endpoint' => (string) ($request['endpoint'] ?? ($connector ? $connector['endpointHint'] : '')),
            'test_path' => (string) ($request['test_path'] ?? ($connector ? $connector['testPathHint'] : '/')),
            'auth_method' => (string) ($request['auth_method'] ?? ($connector['authMethods'][0] ?? '')),
        ];
        $this->render('connection/form.html.twig', [
            'mode' => 'create',
            'connection_id' => null,
            'connectors' => $this->listConnectorTemplates->execute(),
            'authMethods' => $authMethods,
            'defaults' => $defaults,
            'selected_connector' => $connector,
        ]);
    }

    /** @param array<string,mixed> $request */
    public function create(array $request): void
    {
        try {
            $authMethod = AuthMethod::from((string) ($request['auth_method'] ?? ''));
            $connectorId = strtolower(trim((string) ($request['connector_id'] ?? 'custom'))) ?: 'custom';
            if ($connectorId !== 'custom') {
                $connector = $this->getConnectorTemplate->execute($connectorId);
                if (!in_array($authMethod->value, $connector['authMethods'], true)) {
                    throw new \RuntimeException(sprintf(
                        'Authentication method "%s" is not supported by connector "%s".',
                        $authMethod->value,
                        $connector['name'],
                    ));
                }
            }
            $connection = $this->createConnection->execute(new CreateAuthConfigCommand(
                new AuthConfigName((string) ($request['name'] ?? '')),
                new Endpoint((string) ($request['endpoint'] ?? '')),
                $authMethod,
                $this->resolveSecret($authMethod, $request),
                new TestPath((string) ($request['test_path'] ?? '/')),
                $connectorId,
            ));
            $id = $connection->id()?->value();
            Session::addMessageAfterRedirect(__('Connection created. Test it before activation.', 'uxconnector'));
            $this->redirect('/plugins/uxconnector/front/connection.php?id=' . (int) $id);
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
            $this->redirect('/plugins/uxconnector/front/connection.form.php?connector=' . urlencode((string) ($request['connector_id'] ?? 'custom')));
        }
    }

    public function showEditForm(int $id): void
    {
        try {
            $connection = $this->getConnection->execute(new AuthConfigId($id));
            $selectedConnector = $connection->connectorId !== 'custom'
                ? $this->getConnectorTemplate->execute($connection->connectorId)
                : null;
            $authMethods = array_values(array_filter(
                AuthMethod::cases(),
                static fn(AuthMethod $m): bool => $m->isImplemented()
                      && ($selectedConnector === null || in_array($m->value, $selectedConnector['authMethods'], true)),
            ));

            $this->render('connection/form.html.twig', [
                'mode' => 'edit',
                'connection_id' => $id,
                'connectors' => $this->listConnectorTemplates->execute(),
                'authMethods' => $authMethods,
                'defaults' => [
                    'connector_id' => $connection->connectorId,
                    'name' => $connection->name,
                    'endpoint' => $connection->endpoint,
                    'test_path' => $connection->testPath,
                    'auth_method' => $connection->authMethod,
                ],
                'selected_connector' => $selectedConnector,
            ]);
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
            $this->redirect('/plugins/uxconnector/front/connections.php');
        }
    }

    /** @param array<string,mixed> $request */
    public function update(array $request): void
    {
        $id = (int) ($request['id'] ?? 0);
        try {
            $authMethod = AuthMethod::from((string) ($request['auth_method'] ?? ''));
            $current = $this->getConnection->execute(new AuthConfigId($id));
            if ($current->connectorId !== 'custom') {
                $connector = $this->getConnectorTemplate->execute($current->connectorId);
                if (!in_array($authMethod->value, $connector['authMethods'], true)) {
                    throw new \RuntimeException(sprintf(
                        'Authentication method "%s" is not supported by connector "%s".',
                        $authMethod->value,
                        $connector['name'],
                    ));
                }
            }
            $secret = $this->resolveSecret($authMethod, $request);

            $this->updateConnection->execute(new UpdateAuthConfigCommand(
                new AuthConfigId($id),
                new AuthConfigName((string) ($request['name'] ?? '')),
                new Endpoint((string) ($request['endpoint'] ?? '')),
                $authMethod,
                new TestPath((string) ($request['test_path'] ?? '/')),
                $secret,
            ));

            Session::addMessageAfterRedirect(
                __('Connection updated. Re-test it before activation if transport, credential or test settings changed.', 'uxconnector'),
            );
            $this->redirect('/plugins/uxconnector/front/connection.php?id=' . $id);
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
            $this->redirect('/plugins/uxconnector/front/connection.form.php?id=' . $id);
        }
    }

    public function show(int $id): void
    {
        try {
            $connection = $this->getConnection->execute(new AuthConfigId($id));
            $connector = null;
            if ($connection->connectorId !== 'custom') {
                try {
                    $connector = $this->getConnectorTemplate->execute($connection->connectorId);
                } catch (\Throwable) {
                    $connector = null;
                }
            }
            $canReadJobs = Session::haveRight('plugin_uxconnector_syncjob', READ);
            $jobs = [];
            $jobsByTemplate = [];
            if ($canReadJobs) {
                $jobs = array_values(array_filter(
                    $this->listJobs->execute(),
                    static fn($job): bool => (int) $job->authConfigReference === $id,
                ));
                foreach ($jobs as $job) {
                    if ($job->templateId !== null) {
                        $jobsByTemplate[$job->templateId][] = $job;
                    }
                }
            }
            $this->render('connection/detail.html.twig', [
                'connection' => $connection,
                'connector' => $connector,
                'connectors' => $this->listConnectorTemplates->execute(),
                'jobs' => $jobs,
                'jobs_by_template' => $jobsByTemplate,
                'can_read_jobs' => $canReadJobs,
                'can_update_connection' => Session::haveRight('plugin_uxconnector_authconfig', UPDATE),
                'can_delete_connection' => Session::haveRight('plugin_uxconnector_authconfig', PURGE),
                'can_manage_jobs' => Session::haveRight('plugin_uxconnector_syncjob', UPDATE),
                'can_create_jobs' => Session::haveRight('plugin_uxconnector_syncjob', CREATE),
                'can_delete_jobs' => Session::haveRight('plugin_uxconnector_syncjob', PURGE),
                'active_entity_id' => (int) ($_SESSION['glpiactive_entity'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
            $this->redirect('/plugins/uxconnector/front/connections.php');
        }
    }

    /** @param array<string,mixed> $request */
    public function test(array $request): void
    {
        $id = (int) ($request['id'] ?? 0);
        try {
            $result = $this->testConnection->execute(new TestAuthConfigCommand(new AuthConfigId($id)));
            Session::addMessageAfterRedirect($result->success
                ? sprintf(__('Connection test succeeded (HTTP %d).', 'uxconnector'), $result->statusCode)
                : sprintf(__('Connection test failed: %s', 'uxconnector'), $result->errorMessage), false, $result->success ? INFO : ERROR);
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
        $this->redirect($this->connectionRedirectTarget($request, $id));
    }

    /** @param array<string,mixed> $request */
    public function activate(array $request): void
    {
        $this->connectionAction($request, 'activate');
    }
    /** @param array<string,mixed> $request */
    public function disable(array $request): void
    {
        $this->connectionAction($request, 'disable');
    }

    /** @param array<string,mixed> $request */
    public function delete(array $request): void
    {
        $id = (int) ($request['id'] ?? 0);
        try {
            $this->deleteConnection->execute(new DeleteAuthConfigCommand(new AuthConfigId($id)));
            Session::addMessageAfterRedirect(__('Connection deleted.', 'uxconnector'));
            $this->redirect('/plugins/uxconnector/front/connections.php');
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
            $this->redirect('/plugins/uxconnector/front/connection.php?id=' . $id);
        }
    }

    /** @param array<string,mixed> $request */
    public function assignConnector(array $request): void
    {
        $id = (int) ($request['id'] ?? 0);
        try {
            $this->assignConnector->execute(new AuthConfigId($id), (string) ($request['connector_id'] ?? 'custom'));
            Session::addMessageAfterRedirect(__('Connector type associated. Its recommended test resource has been applied; test the connection again before activation.', 'uxconnector'));
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
        $this->redirect('/plugins/uxconnector/front/connection.php?id=' . $id);
    }

    /** @param array<string,mixed> $request */
    public function instantiate(array $request): void
    {
        $id = (int) ($request['id'] ?? 0);
        try {
            $connection = $this->getConnection->execute(new AuthConfigId($id));
            if ($connection->connectorId === 'custom') {
                throw new \RuntimeException('Associate a connector type before activating a standard interaction.');
            }
            $jobId = $this->instantiateJobTemplate->execute(
                $connection->connectorId,
                (string) ($request['job_template_id'] ?? ''),
                $id,
                is_array($request['parameters'] ?? null) ? $request['parameters'] : [],
                trim((string) ($request['fixed_entity_id'] ?? '')) === '' ? null : (int) $request['fixed_entity_id'],
                isset($request['activate_immediately']) && (string) $request['activate_immediately'] === '1',
            );
            Session::addMessageAfterRedirect(sprintf(__('Interaction #%d created.', 'uxconnector'), $jobId));
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
        $this->redirect('/plugins/uxconnector/front/connection.php?id=' . $id);
    }

    /** @param array<string,mixed> $request */
    public function bulkJobAction(array $request, string $action): void
    {
        $connectionId = (int) ($request['connection_id'] ?? 0);
        try {
            $connection = $this->getConnection->execute(new AuthConfigId($connectionId));
            $rawIds = $request['job_ids'] ?? [];
            if (!is_array($rawIds)) {
                throw new \RuntimeException(__('Invalid interaction selection.', 'uxconnector'));
            }

            $selectedIds = [];
            foreach (array_slice($rawIds, 0, 500) as $rawId) {
                if (is_scalar($rawId) && (int) $rawId > 0) {
                    $selectedIds[(int) $rawId] = (int) $rawId;
                }
            }
            if ($selectedIds === []) {
                throw new \RuntimeException(__('Select at least one interaction.', 'uxconnector'));
            }

            $allowedIds = [];
            foreach ($this->listJobs->execute() as $job) {
                if ((int) $job->authConfigReference === $connectionId) {
                    $allowedIds[$job->id] = true;
                }
            }

            if ($action === 'bulk_activate_jobs' && $connection->status !== 'active') {
                throw new \RuntimeException(__('Activate the connection before activating its interactions.', 'uxconnector'));
            }

            $updated = 0;
            $failed = 0;
            foreach ($selectedIds as $jobIdValue) {
                if (!isset($allowedIds[$jobIdValue])) {
                    $failed++;
                    continue;
                }
                try {
                    $jobId = new SyncJobId($jobIdValue);
                    if ($action === 'bulk_activate_jobs') {
                        $this->activateJob->execute(new ActivateSyncJobCommand($jobId));
                    } elseif ($action === 'bulk_pause_jobs') {
                        $this->pauseJob->execute(new PauseSyncJobCommand($jobId));
                    } else {
                        throw new \RuntimeException(__('Unknown bulk interaction action.', 'uxconnector'));
                    }
                    $updated++;
                } catch (\Throwable) {
                    $failed++;
                }
            }

            $message = sprintf(
                __('Bulk interaction update finished: %d updated, %d failed.', 'uxconnector'),
                $updated,
                $failed,
            );
            Session::addMessageAfterRedirect($message, false, $failed === 0 ? INFO : WARNING);
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
        $this->redirect('/plugins/uxconnector/front/connection.php?id=' . $connectionId . '#interactions');
    }

    /** @param array<string,mixed> $request */
    public function jobAction(array $request, string $action): void
    {
        $connectionId = (int) ($request['connection_id'] ?? 0);
        try {
            $jobId = new SyncJobId((int) ($request['job_id'] ?? 0));
            $message = __('Interaction updated.', 'uxconnector');
            match ($action) {
                'activate_job' => $this->activateJob->execute(new ActivateSyncJobCommand($jobId)),
                'pause_job' => $this->pauseJob->execute(new PauseSyncJobCommand($jobId)),
                'run_job' => $this->queueJob->execute($jobId),
                'full_replay_job' => $this->queueFullReplay->execute($jobId),
                'delete_job' => $this->deleteJob->execute(new DeleteSyncJobCommand($jobId)),
                default => throw new \RuntimeException('Unknown interaction action.'),
            };
            if ($action === 'run_job') {
                $message = __('Execution queued. It will run in background.', 'uxconnector');
            } elseif ($action === 'full_replay_job') {
                $message = __('Checkpoint reset and full replay queued. Object links are preserved to keep replay idempotent.', 'uxconnector');
            }
            Session::addMessageAfterRedirect($message);
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
        $this->redirect('/plugins/uxconnector/front/connection.php?id=' . $connectionId);
    }

    /** @param array<string,mixed> $request */
    private function connectionAction(array $request, string $action): void
    {
        $id = (int) ($request['id'] ?? 0);
        try {
            $authId = new AuthConfigId($id);
            if ($action === 'activate') {
                $this->activateConnection->execute(new ActivateAuthConfigCommand($authId));
            } else {
                $this->disableConnection->execute(new DisableAuthConfigCommand($authId));
            }
            Session::addMessageAfterRedirect($action === 'activate' ? __('Connection activated.', 'uxconnector') : __('Connection disabled.', 'uxconnector'));
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
        $this->redirect($this->connectionRedirectTarget($request, $id));
    }

    /** @param array<string,mixed> $request */
    private function connectionRedirectTarget(array $request, int $id): string
    {
        return (($request['return_to'] ?? '') === 'connections')
            ? '/plugins/uxconnector/front/connections.php'
            : '/plugins/uxconnector/front/connection.php?id=' . $id;
    }

    /** @param array<string,mixed> $request */
    private function resolveSecret(AuthMethod $method, array $request): ?string
    {
        if ($method === AuthMethod::OAUTH2_CLIENT_CREDENTIALS) {
            return $this->oauth2SecretComposer->compose($request);
        }
        if ($method === AuthMethod::OAUTH2_PASSWORD) {
            return $this->oauth2PasswordSecretComposer->compose($request);
        }
        if ($method === AuthMethod::GLPI_REST_V1) {
            return $this->glpiRestV1SecretComposer->compose($request);
        }
        $secret = trim((string) ($request['secret'] ?? ''));
        return $secret === '' ? null : $secret;
    }

}
