<?php

declare(strict_types=1);
spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'GlpiPlugin\\Uxconnector\\';
    if (str_starts_with($class, $prefix)) {
        require $argv[1].'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});
use GlpiPlugin\Uxconnector\Application\Sync\Service\{InboundRecordProcessor,ProcessQueuedFailureRetriesService};
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\{SyncFailure,SyncJob};
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncErrorCategory;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\{SyncFailureRepositoryInterface,SyncJobRepositoryInterface};
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\{SyncExecutionId,SyncJobId};

$failures = new class () implements SyncFailureRepositoryInterface {
    public array $statuses = [];
    public array $rows = [];
    public function add(SyncFailure $f): void
    {
    }
    public function findByExecution(SyncExecutionId $id): array
    {
        return [];
    }
    public function updateRetryStatus(int $id, string $status): void
    {
        $this->statuses[$id] = $status;
    }
    public function queueByExecution(SyncExecutionId $id): int
    {
        return 0;
    }
    public function findQueued(int $limit = 50): array
    {
        return $this->rows;
    }
};
$jobs = new class () implements SyncJobRepositoryInterface {
    public function save(SyncJob $j): SyncJob
    {
        return $j;
    }
    public function findById(SyncJobId $id): ?SyncJob
    {
        return (new ReflectionClass(SyncJob::class))->newInstanceWithoutConstructor();
    }
    public function rotateWebhookSigningSecret(SyncJob $e, string $s): void
    {
    }
    public function findAll(): array
    {
        return [];
    }
    public function findRunnable(): array
    {
        return [];
    }
    public function delete(SyncJobId $id): void
    {
    }
    public function exists(SyncJobId $id): bool
    {
        return true;
    }
};
// A deliberately uninitialised processor makes accidental processing fail;
// the expected status must be snapshot_redacted, never the generic failed status.
$processor = (new ReflectionClass(InboundRecordProcessor::class))->newInstanceWithoutConstructor();
foreach (['{"nested":{"pin":"[REDACTED]"}}','invalid json'] as $index => $snapshot) {
    $failures->rows[] = new SyncFailure(
        new SyncExecutionId(1),
        new SyncJobId(1),
        'qa',
        '1',
        null,
        null,
        null,
        'write',
        'error',
        SyncErrorCategory::POST_PROCESSING,
        null,
        'fictitious',
        null,
        $snapshot,
        1,
        1,
        null,
        id:$index + 1
    );
}
$result = (new ProcessQueuedFailureRetriesService($failures, $jobs, $processor))->execute();
if ($failures->statuses !== [1 => 'snapshot_redacted',2 => 'snapshot_invalid'] || $result['failed'] !== 2 || $result['succeeded'] !== 0) {
    throw new LogicException('Retry did not reject unfit evidence before processing');
}
echo "OK RC12 retry service: redacted and invalid snapshots stop before the record processor\n";
