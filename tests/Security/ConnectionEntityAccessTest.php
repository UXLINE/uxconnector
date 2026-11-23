<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use GlpiPlugin\Uxconnector\Application\Sync\Service\JobEntityAccess;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\UnknownEntityPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use GlpiPlugin\Uxconnector\Presentation\Connection\Controller\ConnectionController;
use GlpiPlugin\Uxconnector\Presentation\Engine\Controller\AuthConfigController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionEntityAccessTest extends TestCase
{
    private function job(int $connection, ?EntityAssignmentStrategy $strategy): SyncJob
    {
        return SyncJob::create(
            new SyncJobName('QA scope'),
            new AuthConfigReference($connection),
            SyncDirection::INBOUND,
            new ResourcePath('/computers'),
            TargetItemtype::COMPUTER,
            new MatchField('serial'),
            new ExternalIdField('id'),
            [new FieldMapping('serial', 'serial')],
            $strategy,
        );
    }

    private function access(array $jobs, array $entities, bool $all = false): JobEntityAccess
    {
        $repository = $this->createMock(SyncJobRepositoryInterface::class);
        $repository->method('findAll')->willReturn($jobs);
        $repository->expects(self::never())->method('findRunnable');
        return new JobEntityAccess($repository, static fn (?array $ids): bool => $ids === null
            ? $all : array_diff($ids, $entities) === []);
    }

    public function testSharedConnectionRequiresAllLinkedJobsEvenWhenPaused(): void
    {
        $other = $this->job(12, EntityAssignmentStrategy::fixed(11));
        $other->activate();
        $other->pause();
        $access = $this->access([$this->job(12, EntityAssignmentStrategy::fixed(10)), $other], [10]);
        $this->expectException(\DomainException::class);
        $access->assertConnectionId(new AuthConfigId(12));
    }

    public function testFullScopeAllowsSharedConnectionAndUnrelatedJobsDoNotBlock(): void
    {
        $access = $this->access([
            $this->job(12, EntityAssignmentStrategy::fixed(0)),
            $this->job(12, EntityAssignmentStrategy::fixed(10)),
            $this->job(99, EntityAssignmentStrategy::fixed(11)),
        ], [0, 10]);
        $access->assertConnectionId(new AuthConfigId(12));
        $access->assertConnectionId(new AuthConfigId(123));
        $this->addToAssertionCount(2);
    }

    public function testMappingDefaultEntityCannotBeIgnored(): void
    {
        $job = $this->job(12, EntityAssignmentStrategy::mapped('entity', ['a' => 10], UnknownEntityPolicy::USE_DEFAULT, 0));
        $this->expectException(\DomainException::class);
        $this->access([$job], [10])->assertConnectionId(new AuthConfigId(12));
    }

    public function testUnscopedLegacyJobRequiresGlobalAccess(): void
    {
        $job = $this->job(12, null);
        $this->access([$job], [], true)->assertConnectionId(new AuthConfigId(12));
        $this->expectException(\DomainException::class);
        $this->access([$job], [10])->assertConnectionId(new AuthConfigId(12));
    }

    public static function routes(): iterable
    {
        foreach (['update', 'test', 'activate', 'disable', 'delete', 'assignConnector'] as $action) {
            yield 'connection/' . $action => [ConnectionController::class, $action];
        }
        foreach (['test', 'activate', 'disable', 'delete'] as $action) {
            yield 'legacy/' . $action => [AuthConfigController::class, $action];
        }
    }

    #[DataProvider('routes')]
    public function testEveryMutationRouteRejectsBeforeCallingAnyService(string $class, string $action): void
    {
        // Uninitialized action services make any premature side effect fail the expected denial assertion.
        $reflection = new \ReflectionClass($class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('jobEntityAccess')->setValue($controller, $this->access([
            $this->job(12, EntityAssignmentStrategy::fixed(11)),
        ], [10]));
        \Session::$messages = [];
        \Html::$redirects = [];
        $controller->$action(['id' => 12, 'endpoint' => 'https://attacker.example', 'secret' => 'QA-only']);
        self::assertSame(['The interaction targets entities outside your active GLPI scope.'], \Session::$messages);
        self::assertCount(1, \Html::$redirects);
    }
}
