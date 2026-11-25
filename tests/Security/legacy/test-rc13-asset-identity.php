<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncJobStatus;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Exception\InvalidSyncJobStateException;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\OutboundConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;

spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'GlpiPlugin\\Uxconnector\\';
    if (str_starts_with($class, $prefix)) {
        require $argv[1] . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new LogicException($message);
    }
}

/** @param list<FieldMapping> $mappings */
function createJob(array $mappings, string $match = 'asset_identity'): SyncJob
{
    return SyncJob::create(
        new SyncJobName('Before edit'),
        new AuthConfigReference(1),
        SyncDirection::INBOUND,
        new ResourcePath('/api.php/Assets/Computer?start=0&limit=1'),
        TargetItemtype::COMPUTER,
        new MatchField($match),
        new ExternalIdField('id'),
        $mappings,
        EntityAssignmentStrategy::fixed(10),
        templateId: 'glpi-hl.assets.computers.inbound',
        externalObjectType: 'glpi_hl_computer',
    );
}

/** @param list<FieldMapping> $mappings */
function editJob(SyncJob $job, array $mappings, string $match = 'asset_identity'): void
{
    $job->reconfigure(
        new SyncJobName('After edit'),
        new AuthConfigReference(1),
        SyncDirection::INBOUND,
        new ResourcePath('/api.php/Assets/Computer?start=0&limit=1'),
        TargetItemtype::COMPUTER,
        new MatchField($match),
        new ExternalIdField('id'),
        $mappings,
        EntityAssignmentStrategy::fixed(10),
        CollectionPath::root(),
        PaginationConfig::none(),
        InteractionTrigger::POLLING,
        'glpi_hl_computer',
        OutboundConfig::none(),
        null,
    );
}

$mappings = array_map(
    static fn (string $field) => new FieldMapping($field, $field, false, null),
    ['name', 'serial', 'otherserial', 'uuid', 'comment', 'contact', 'contact_num'],
);
$job = createJob($mappings);
$job->activate();
$job->pause();
editJob($job, $mappings);
check($job->name()->value() === 'After edit', 'Paused job was not saved.');
check($job->matchField()->value() === 'asset_identity', 'Composite matcher changed.');
check($job->fieldMappings() === $mappings, 'User mappings changed.');
check($job->entityAssignmentStrategy()?->fixedEntityId() === 10, 'Entity scope changed.');
check($job->externalObjectType() === 'glpi_hl_computer', 'ObjectLink namespace changed.');
check($job->status() === SyncJobStatus::PAUSED, 'Editing activated the job.');
echo "OK: exact seven user mappings, paused edit, matcher, entity and ObjectLink namespace preserved.\n";

foreach (['uuid', 'serial', 'otherserial', 'name'] as $field) {
    $single = [new FieldMapping('source_' . $field, $field)];
    $candidate = createJob($single);
    editJob($candidate, $single);
}
echo "OK: each mapped identity field supports creation and editing.\n";

foreach (['asset_identity', 'serial'] as $match) {
    $candidate = createJob($mappings);
    $before = serialize($candidate);
    try {
        editJob($candidate, [new FieldMapping('comment', 'comment')], $match);
        throw new LogicException('Invalid mapping was accepted.');
    } catch (InvalidSyncJobStateException $error) {
        $expected = $match === 'asset_identity'
            ? 'requires at least one mapped identity field'
            : 'does not correspond to the targetField';
        check(str_contains($error->getMessage(), $expected), 'Unexpected validation error.');
    }
    check(serialize($candidate) === $before, 'Rejected edit changed the aggregate.');
}
echo "OK: missing identity and missing ordinary match field rejected before any mutation.\n";

$candidate = createJob($mappings);
editJob($candidate, $mappings, 'serial');
check($candidate->matchField()->value() === 'serial', 'Ordinary matching regressed.');
$candidate->activate();
$before = serialize($candidate);
try {
    editJob($candidate, $mappings);
    throw new LogicException('Active job could be edited.');
} catch (DomainException $error) {
    check(str_contains($error->getMessage(), 'must be paused'), 'Unexpected active-job rejection.');
}
check(serialize($candidate) === $before, 'Rejected active edit changed the job.');
echo "OK: ordinary matching preserved and active-job editing still rejected.\n";

$candidate = createJob([]);
editJob($candidate, []);
try {
    $candidate->activate();
    throw new LogicException('Empty mappings could be activated.');
} catch (InvalidSyncJobStateException) {
}
echo "OK: empty draft remains editable but cannot be activated.\n";
