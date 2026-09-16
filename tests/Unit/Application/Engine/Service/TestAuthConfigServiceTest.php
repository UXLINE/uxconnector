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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Application\Engine\Command\TestAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Service\TestAuthConfigService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthConfigStatus;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestAuthConfigService::class)]
final class TestAuthConfigServiceTest extends TestCase
{
   private function makeAuthConfig(): AuthConfig {
       $authConfig = AuthConfig::create(
           new AuthConfigName('Microsoft Graph'),
           new Endpoint('https://graph.microsoft.com/v1.0'),
           AuthMethod::NONE,
           new TestPath('/health')
       );
       $authConfig->assignId(new AuthConfigId(1));

       return $authConfig;
   }

   public function testSuccessfulTestMovesStatusToTesting(): void {
       $repository = new InMemoryTestAuthConfigRepo([$this->makeAuthConfig()]);
       $httpEngine = new FakeHttpEngineForTest(new HttpResponse(200, [], '{}'));

       $service = new TestAuthConfigService($repository, $httpEngine);

       $result = $service->execute(new TestAuthConfigCommand(new AuthConfigId(1)));

       self::assertTrue($result->success);
       self::assertSame(200, $result->statusCode);
       self::assertNotNull($result->durationMs);
       self::assertNull($result->errorMessage);
       self::assertSame(AuthConfigStatus::TESTING, $repository->findById(new AuthConfigId(1))->status());
   }

   public function test401ResponseIsAuthenticationFailureAndMarksError(): void {
       $repository = new InMemoryTestAuthConfigRepo([$this->makeAuthConfig()]);
       $httpEngine = new FakeHttpEngineForTest(new HttpResponse(401, [], '{}'));

       $service = new TestAuthConfigService($repository, $httpEngine);

       $result = $service->execute(new TestAuthConfigCommand(new AuthConfigId(1)));

       self::assertFalse($result->success);
       self::assertSame(401, $result->statusCode);
       self::assertSame(AuthConfigStatus::ERROR, $repository->findById(new AuthConfigId(1))->status());
   }

   public function testConfiguredTestPathIsSentToHttpEngine(): void {
       $repository = new InMemoryTestAuthConfigRepo([$this->makeAuthConfig()]);
       $httpEngine = new RecordingHttpEngineForTest(new HttpResponse(200, [], '{}'));
       $service = new TestAuthConfigService($repository, $httpEngine);

       $result = $service->execute(new TestAuthConfigCommand(new AuthConfigId(1)));

       self::assertTrue($result->success);
       self::assertSame('/health', $httpEngine->lastRequest?->path);
   }

   public function test5xxResponseMarksTheAuthConfigInError(): void {
       $repository = new InMemoryTestAuthConfigRepo([$this->makeAuthConfig()]);
       $httpEngine = new FakeHttpEngineForTest(new HttpResponse(503, [], '{}'));

       $service = new TestAuthConfigService($repository, $httpEngine);

       $result = $service->execute(new TestAuthConfigCommand(new AuthConfigId(1)));

       self::assertFalse($result->success);
       self::assertSame(503, $result->statusCode);
       self::assertSame(AuthConfigStatus::ERROR, $repository->findById(new AuthConfigId(1))->status());
   }

   public function testTransportFailureMarksTheAuthConfigInError(): void {
       $repository = new InMemoryTestAuthConfigRepo([$this->makeAuthConfig()]);
       $httpEngine = new ThrowingHttpEngineForTest();

       $service = new TestAuthConfigService($repository, $httpEngine);

       $result = $service->execute(new TestAuthConfigCommand(new AuthConfigId(1)));

       self::assertFalse($result->success);
       self::assertNull($result->statusCode);
       self::assertNotNull($result->errorMessage);
       self::assertSame(AuthConfigStatus::ERROR, $repository->findById(new AuthConfigId(1))->status());
   }

   public function testUnknownAuthConfigIsRejected(): void {
       $service = new TestAuthConfigService(
           new InMemoryTestAuthConfigRepo([]),
           new FakeHttpEngineForTest(new HttpResponse(200, [], '{}'))
       );

       $this->expectException(\GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::class);

       $service->execute(new TestAuthConfigCommand(new AuthConfigId(999)));
   }
}

/**
 * @internal test double
 */
final class InMemoryTestAuthConfigRepo implements AuthConfigRepositoryInterface
{
    /** @var array<int, AuthConfig> */
   private array $storage = [];

   public function __construct(array $items) {
      foreach ($items as $item) {
          $this->storage[$item->id()->value()] = $item;
      }
   }

   public function save(AuthConfig $authConfig): AuthConfig {
       $this->storage[$authConfig->id()->value()] = $authConfig;

       return $authConfig;
   }

   public function findById(AuthConfigId $id): ?AuthConfig {
       return $this->storage[$id->value()] ?? null;
   }

   public function findAll(): array {
       return array_values($this->storage);
   }

   public function delete(AuthConfigId $id): void {
       unset($this->storage[$id->value()]);
   }

   public function exists(AuthConfigId $id): bool {
       return isset($this->storage[$id->value()]);
   }
}

/**
 * @internal test double
 */
final class FakeHttpEngineForTest implements HttpEngineInterface
{
   public function __construct(
        private readonly HttpResponse $response
    ) {
   }

   public function execute(AuthConfig $authConfig, HttpRequest $request): HttpResponse {
       return $this->response;
   }
}

/**
 * @internal test double
 */
final class ThrowingHttpEngineForTest implements HttpEngineInterface
{
   public function execute(AuthConfig $authConfig, HttpRequest $request): HttpResponse {
       throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::transportFailure('connection refused');
   }
}


/** @internal test double */
final class RecordingHttpEngineForTest implements HttpEngineInterface
{
   public ?HttpRequest $lastRequest = null;

   public function __construct(private readonly HttpResponse $response) {
   }

   public function execute(AuthConfig $authConfig, HttpRequest $request): HttpResponse {
       $this->lastRequest = $request;
       return $this->response;
   }
}
