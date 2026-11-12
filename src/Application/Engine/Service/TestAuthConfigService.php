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

namespace GlpiPlugin\Uxconnector\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Application\Engine\Command\TestAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\DTO\TestAuthConfigResult;
use GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\SessionLifecycleHttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;

/**
 * Orchestrates the "test this connection" use case.
 *
 * A test is an authenticated HTTP GET to the AuthConfig's configured
 * TestPath. Only a 2xx response proves that the target resource accepted
 * the request. 3xx, 4xx and 5xx responses are failures: in particular,
 * HTTP 401/403 must never move a configuration to TESTING/activatable.
 *
 * The status is set to TESTING before the call so an in-progress test is
 * visible. Any transport or HTTP-level failure then moves it to ERROR.
 */
final class TestAuthConfigService
{
    public function __construct(
        private readonly AuthConfigRepositoryInterface $repository,
        private readonly HttpEngineInterface $httpEngine
    ) {
    }

    /**
     * @throws AuthConfigNotFoundException if the command's AuthConfigId
     *         does not resolve to an existing AuthConfig.
     */
    public function execute(TestAuthConfigCommand $command): TestAuthConfigResult
    {
        $authConfig = $this->repository->findById($command->authConfigId);

        if ($authConfig === null) {
            throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::forId(
                $command->authConfigId
            );
        }

        $wasActive = $authConfig->isUsable();
        $authConfig->markTesting();
        $authConfig = $this->repository->save($authConfig);

        $startedAt = microtime(true);

        try {
            $response = $this->httpEngine->execute(
                $authConfig,
                new HttpRequest(HttpMethod::GET, $authConfig->testPath()->value()),
            );
            $durationMs = (microtime(true) - $startedAt) * 1000;

            if ($response->statusCode < 200 || $response->statusCode >= 300) {
                $authConfig->markError();
                $this->repository->save($authConfig);

                return new TestAuthConfigResult(
                    success: false,
                    statusCode: $response->statusCode,
                    durationMs: $durationMs,
                    errorMessage: sprintf(
                        'The test resource "%s" responded with HTTP %d.',
                        $authConfig->testPath()->value(),
                        $response->statusCode,
                    ),
                );
            }

            // Re-testing an already active connection must not silently disable
            // it. A successful health check restores ACTIVE immediately; a draft
            // or disabled connection stays TESTING so the administrator can make
            // the explicit activation decision.
            if ($wasActive) {
                $authConfig->activate();
                $this->repository->save($authConfig);
            }

            return new TestAuthConfigResult(
                success: true,
                statusCode: $response->statusCode,
                durationMs: $durationMs,
                errorMessage: null,
            );
        } catch (\Throwable $exception) {
            $authConfig->markError();
            $this->repository->save($authConfig);

            return new TestAuthConfigResult(
                success: false,
                statusCode: null,
                durationMs: null,
                errorMessage: $exception->getMessage(),
            );
        } finally {
            if ($this->httpEngine instanceof SessionLifecycleHttpEngineInterface) {
                try {
                    $this->httpEngine->closeSession($authConfig);
                } catch (\Throwable) {
                }
            }
        }
    }
}
