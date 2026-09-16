<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ExecuteSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\FieldValueTransformer;
use GlpiPlugin\Uxconnector\Application\Sync\Service\JsonPathValueReader;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncExecution;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncExecutionRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ReconciliationContext;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use GlpiPlugin\Uxconnector\Domain\Sync\Writer\SyncItemWriterInterface;

require_once __DIR__ . '/../vendor/autoload.php';

$total = max(1, (int) ($argv[1] ?? 10000));
$pageSize = max(1, min(1000, (int) ($argv[2] ?? 100)));
$pages = (int) ceil($total / $pageSize);

final class VolumeJobRepository implements SyncJobRepositoryInterface
{
    public function __construct(private SyncJob $job) {}
    public function save(SyncJob $syncJob): SyncJob { return $syncJob; }
    public function findById(SyncJobId $id): ?SyncJob { return $this->job; }
    public function findAll(): array { return [$this->job]; }
    public function findRunnable(): array { return [$this->job]; }
    public function delete(SyncJobId $id): void {}
    public function exists(SyncJobId $id): bool { return true; }
}

final class VolumeAuthRepository implements AuthConfigRepositoryInterface
{
    public function __construct(private AuthConfig $auth) {}
    public function save(AuthConfig $authConfig): AuthConfig { return $authConfig; }
    public function findById(AuthConfigId $id): ?AuthConfig { return $this->auth; }
    public function findAll(): array { return [$this->auth]; }
    public function delete(AuthConfigId $id): void {}
    public function exists(AuthConfigId $id): bool { return true; }
}

final class VolumeHttpEngine implements HttpEngineInterface
{
    public int $calls = 0;

    public function __construct(private int $total, private int $pageSize) {}

    public function execute(AuthConfig $authConfig, HttpRequest $request): HttpResponse
    {
        $this->calls++;
        parse_str((string) (parse_url($request->path, PHP_URL_QUERY) ?? ''), $query);
        $page = max(1, (int) ($query['page'] ?? 1));
        $start = (($page - 1) * $this->pageSize) + 1;
        $end = min($this->total, $start + $this->pageSize - 1);
        $results = [];

        for ($id = $start; $id <= $end; $id++) {
            $results[] = [
                'id' => $id,
                'subject' => 'Synthetic Zendesk ticket ' . $id,
                'status' => 'open',
            ];
        }

        $hasMore = $end < $this->total;
        return new HttpResponse(200, [], json_encode([
            'results' => $results,
            'links' => [
                'next' => $hasMore ? '/api/v2/search/export.json?page=' . ($page + 1) : null,
            ],
            'meta' => [
                'has_more' => $hasMore,
                'after_cursor' => $hasMore ? 'synthetic-' . $page : null,
            ],
        ], JSON_THROW_ON_ERROR));
    }
}

final class VolumeWriter implements SyncItemWriterInterface
{
    public int $count = 0;
    public function write(ReconciliationContext $context, array $mappedFields): bool
    {
        $this->count++;
        return true;
    }
}

final class VolumeExecutionRepository implements SyncExecutionRepositoryInterface
{
    private int $nextId = 1;
    public function save(SyncExecution $execution): SyncExecution
    {
        if ($execution->id() === null) {
            $execution->assignId(new SyncExecutionId($this->nextId++));
        }
        return $execution;
    }
    public function findById(SyncExecutionId $id): ?SyncExecution { return null; }
    public function findBySyncJobId(SyncJobId $syncJobId): array { return []; }
    public function delete(SyncExecutionId $id): void {}
}

final class VolumeTransactionManager implements TransactionManagerInterface
{
    public function begin(): void {}
    public function commit(): void {}
    public function rollback(): void {}
}

final class VolumeEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(DomainEvent $event): void {}
}

$job = SyncJob::create(
    new SyncJobName('Synthetic Zendesk volume test'),
    new AuthConfigReference(1),
    SyncDirection::INBOUND,
    new ResourcePath('/api/v2/search/export.json?page=1'),
    TargetItemtype::TICKET,
    new MatchField('externalid'),
    new ExternalIdField('id'),
    [
        new FieldMapping('id', 'externalid', required: true),
        new FieldMapping('subject', 'name', required: true),
        new FieldMapping('status', 'status', required: true, transform: 'map:open=2|*=1'),
    ],
    null,
    new CollectionPath('results'),
    PaginationConfig::fromArray('next_link', ['next_link_response_path' => 'links.next'])
);
$job->assignId(new SyncJobId(1));
$job->activate();
$job->releaseEvents();

$auth = AuthConfig::create(
    new AuthConfigName('Synthetic Zendesk'),
    new Endpoint('https://example.invalid'),
    AuthMethod::NONE
);
$auth->assignId(new AuthConfigId(1));
$auth->markTesting();
$auth->activate();

$engine = new VolumeHttpEngine($total, $pageSize);
$writer = new VolumeWriter();
$service = new ExecuteSyncJobService(
    new VolumeJobRepository($job),
    new VolumeExecutionRepository(),
    new VolumeAuthRepository($auth),
    $engine,
    $writer,
    new FieldValueTransformer(),
    new JsonPathValueReader(),
    new TargetFieldPolicy(),
    new VolumeTransactionManager(),
    new VolumeEventDispatcher()
);

$before = memory_get_usage(true);
$peakBefore = memory_get_peak_usage(true);
$started = microtime(true);
$execution = $service->execute(new ExecuteSyncJobCommand(new SyncJobId(1)));
$elapsed = microtime(true) - $started;
$peak = memory_get_peak_usage(true);

printf("UXConnector Zendesk synthetic volume test\n");
printf("Records requested : %d\n", $total);
printf("Page size         : %d\n", $pageSize);
printf("Expected pages    : %d\n", $pages);
printf("HTTP pages read   : %d\n", $engine->calls);
printf("Processed         : %d\n", $execution->itemsProcessed());
printf("Failed            : %d\n", $execution->itemsFailed());
printf("Status            : %s\n", $execution->status()->value);
printf("Writer count      : %d\n", $writer->count);
printf("Elapsed           : %.3f s\n", $elapsed);
printf("Memory start      : %.2f MiB\n", $before / 1048576);
printf("Memory peak       : %.2f MiB\n", $peak / 1048576);
printf("Peak delta        : %.2f MiB\n", max(0, $peak - $peakBefore) / 1048576);

$ok = $execution->status()->value === 'success'
    && $execution->itemsProcessed() === $total
    && $execution->itemsFailed() === 0
    && $writer->count === $total
    && $engine->calls === $pages;

exit($ok ? 0 : 1);
