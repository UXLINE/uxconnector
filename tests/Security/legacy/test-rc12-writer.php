<?php

declare(strict_types=1);
spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'GlpiPlugin\\Uxconnector\\';
    if (str_starts_with($class, $prefix)) {
        require $argv[1].'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});
use GlpiPlugin\Uxconnector\Application\Sync\Service\SourceSnapshotSanitizer;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\{ObjectLink,SyncJob};
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\{SyncDirection, TargetItemtype};
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\{AuthConfigReference, EntityAssignmentStrategy, ExternalId, LinkedTargetReference, MatchField, ObjectLinkId, ReconciliationContext, SyncJobId};
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\MigrationEntityScope;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Writer\GlpiItemWriter;

function check(bool $ok, string $m): void
{
    if (!$ok) {
        throw new LogicException($m);
    }
}
function rejected(callable $f): void
{
    try {
        $f();
    } catch (RuntimeException) {
        return;
    }throw new LogicException('Expected rejection');
}
// These doubles execute the production writer. They do not emulate all GLPI hooks or SQL transactions.
class DBmysql
{
    public function fieldExists(string $table, string $field): bool
    {
        return in_array($table, ['glpi_locations','glpi_lines','glpi_networkequipments','glpi_softwarelicenses','glpi_softwares','glpi_states'], true);
    }
}
class CommonDBTM
{
    public static array $rows = [];
    public static int $writes = 0;
    public array $fields = [];
    public function getFromDB($id): bool
    {
        $this->fields = self::$rows[static::class][$id] ?? [];
        return $this->fields !== [];
    }
    public function add(array $data): int
    {
        self::$writes++;
        $id = count(self::$rows[static::class] ?? []) + 100;
        $this->fields = $data + ['id' => $id];
        self::$rows[static::class][$id] = $this->fields;
        return $id;
    }
    public function update(array $data): bool
    {
        self::$writes++;
        self::$rows[static::class][$data['id']] = array_replace(self::$rows[static::class][$data['id']], $data);
        return true;
    }
}
class ITILFollowup extends CommonDBTM
{
}class Ticket extends CommonDBTM
{
}class Change extends CommonDBTM
{
}
class Problem extends CommonDBTM
{
}class User extends CommonDBTM
{
}class Entity extends CommonDBTM
{
}
class Profile_User
{
    public static array $entities = [7 => [10,11]];
    public static function getUserEntities($id, $recursive = true): array
    {
        return self::$entities[$id] ?? [];
    }
}
CommonDBTM::$rows = [Ticket::class => [1 => ['id' => 1,'entities_id' => 10,'is_deleted' => 0],2 => ['id' => 2,'entities_id' => 20,'is_deleted' => 0],3 => ['id' => 3,'entities_id' => 10,'is_deleted' => 1]],
    Change::class => [1 => ['id' => 1,'entities_id' => 10]],Problem::class => [1 => ['id' => 1,'entities_id' => 10]],
    User::class => [7 => ['id' => 7,'is_active' => 1],8 => ['id' => 8,'is_active' => 1]],Entity::class => [0 => ['id' => 0],10 => ['id' => 10],20 => ['id' => 20]]];
$links = new class () implements ObjectLinkRepositoryInterface {
    public array $rows = [];
    public function save(ObjectLink $link): ObjectLink
    {
        $this->rows[$link->externalObjectType().':'.$link->externalId()->value()] = $link;
        return $link;
    }
    public function findByExternalId(AuthConfigReference $auth, string $type, ExternalId $id): ?ObjectLink
    {
        return $this->rows[$type.':'.$id->value()] ?? null;
    }
    public function findByGlpiItem(AuthConfigReference $a, TargetItemtype $t, int $id, ?string $e = null): ?ObjectLink
    {
        return null;
    }
    public function delete(ObjectLinkId $id): void
    {
    }
};
$writer = new GlpiItemWriter(new DBmysql(), $links);
$context = static fn (string $id, ?int $entity = 10) => new ReconciliationContext(new SyncJobId(1), new AuthConfigReference(1), TargetItemtype::ITIL_FOLLOWUP, new MatchField('items_id'), new ExternalId($id), 'comment', $entity);
$data = ['itemtype' => 'Ticket','items_id' => 1,'content' => 'QA followup','users_id' => 0];
foreach ([['items_id' => 2],['items_id' => 3],['items_id' => 999],['items_id' => '1x'],['itemtype' => 'User'],['users_id' => 7]] as $bad) {
    $before = CommonDBTM::$writes;
    rejected(fn () => $writer->write($context('invalid'), array_replace($data, $bad)));
    check(CommonDBTM::$writes === $before, 'Denied write had effects');
}
rejected(fn () => $writer->write($context('missing-entity', null), $data));
check($writer->write($context('first'), $data), 'First followup failed');
check($writer->write($context('second'), $data), 'Second followup failed');
check(count(CommonDBTM::$rows[ITILFollowup::class]) === 2, 'Two comments on same parent merged');
$before = CommonDBTM::$writes;
check($writer->write($context('first'), $data), 'Replay failed');
check(CommonDBTM::$writes === $before, 'Replay duplicated a comment');
$links->save(ObjectLink::create(new SyncJobId(1), new AuthConfigReference(1), new ExternalId('source-user'), TargetItemtype::USER, 7, 'user'));
$linked = array_replace($data, ['users_id' => new LinkedTargetReference('user', 'source-user')]);
check($writer->write($context('linked'), $linked), 'Linked author in native profile scope failed');
Profile_User::$entities[7] = [20];
$before = CommonDBTM::$writes;
rejected(fn () => $writer->write($context('forbidden-author'), $linked));
check(CommonDBTM::$writes === $before, 'Author denied after write');
Profile_User::$entities[7] = [10];
CommonDBTM::$rows[User::class][7]['is_active'] = 0;
rejected(fn () => $writer->write($context('inactive-author'), $linked));
CommonDBTM::$rows[User::class][7]['is_active'] = 1;
CommonDBTM::$rows[User::class][7]['is_deleted'] = 1;
rejected(fn () => $writer->write($context('deleted-author'), $linked));
CommonDBTM::$rows[Ticket::class][1]['entities_id'] = 20;
rejected(fn () => $writer->write($context('first'), $data));
echo "OK RC12 native-write doubles: parent type/entity/deletion, imported author scope/activity, no write on rejection, distinct comments and replay idempotence\n";
$db = new DBmysql();
check(MigrationEntityScope::constrain($db, 'glpi_locations', ['name' => 'same'], 10) === ['name' => 'same','entities_id' => 10], 'Lookup unscoped');
check(MigrationEntityScope::constrain($db, 'glpi_manufacturers', ['name' => 'global'], 10) === ['name' => 'global'], 'Global dropdown changed');
rejected(fn () => MigrationEntityScope::constrain($db, 'glpi_lines', ['entities_id' => 0], 10));
rejected(fn () => MigrationEntityScope::constrain($db, 'glpi_lines', [], null));
$job = (new ReflectionClass(SyncJob::class))->newInstanceWithoutConstructor();
$prop = new ReflectionProperty($job, 'entityAssignmentStrategy');
$prop->setValue($job, EntityAssignmentStrategy::fixed(10));
check(MigrationEntityScope::resolve($job, []) === 10, 'Explicit entity lost');
$prop->setValue($job, EntityAssignmentStrategy::fixed(0));
check(MigrationEntityScope::resolve($job, []) === 0, 'Explicit root lost');
$prop->setValue($job, null);
rejected(fn () => MigrationEntityScope::resolve($job, []));
$prop->setValue($job, EntityAssignmentStrategy::fixed(999));
rejected(fn () => MigrationEntityScope::resolve($job, []));
echo "OK RC12 migration scopes: explicit entity/root, no implicit root, invalid entity rejected, global dropdowns preserved\n";
$sanitizer = new SourceSnapshotSanitizer();
$safe = ['name' => 'QA','nested' => ['value' => 0]];
check(SourceSnapshotSanitizer::isReplayable($safe), 'Intact record rejected');
$redacted = json_decode($sanitizer->encode(['name' => 'QA','nested' => ['puk' => 'FAKE-SECRET']]), true);
check(!SourceSnapshotSanitizer::isReplayable($redacted), 'Redacted record replayable');
echo "OK RC12 snapshot: nested redacted values rejected, intact snapshot accepted\n";

// Exercise native-model adapters with an existing category in another entity.
class ITILCategory extends CommonDBTM
{
    public function find(array $criteria, array $order = [], int $limit = 0): array
    {
        return array_filter(self::$rows[self::class] ?? [], static fn (array $row): bool => count(array_diff_assoc($criteria, $row)) === 0);
    }
}
$job = SyncJob::create(
    new \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName('QA'),
    new AuthConfigReference(1),
    SyncDirection::INBOUND,
    new \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath('/topics'),
    TargetItemtype::ITIL_CATEGORY,
    new MatchField('name'),
    new \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField('id'),
    entityAssignmentStrategy:EntityAssignmentStrategy::fixed(10)
);
(new ReflectionProperty($job, 'id'))->setValue($job, new SyncJobId(1));
CommonDBTM::$rows[ITILCategory::class] = [1 => ['id' => 1,'name' => 'QA','itilcategories_id' => 0,'entities_id' => 20,'is_recursive' => 1]];
$categories = new \GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskTopicCategoryService($links);
$id = $categories->ensureForTopic($job, 'topic-qa', 'QA');
check($id !== null && $id !== 1, 'Adopted category from entity B');
check(CommonDBTM::$rows[ITILCategory::class][$id]['entities_id'] === 10, 'Category created in wrong entity');
check(CommonDBTM::$rows[ITILCategory::class][$id]['is_recursive'] === 0, 'New category exposed to children');
check($categories->ensureForTopic($job, 'topic-qa', 'QA') === $id, 'Category replay duplicated');
$job->changeEntityAssignmentStrategy(EntityAssignmentStrategy::fixed(20));
$before = CommonDBTM::$writes;
rejected(fn () => $categories->ensureForTopic($job, 'topic-qa', 'renamed'));
check(CommonDBTM::$writes === $before, 'Category changed outside entity');
echo "OK RC12 Zendesk categories: entity scope, no recursive exposure by default, replay, cross-entity link rejected\n";

$edit = (new ReflectionClass(\GlpiPlugin\Uxconnector\Presentation\Sync\Controller\SyncJobEditController::class))->newInstanceWithoutConstructor();
$entities = new ReflectionMethod($edit, 'parseEntityAssignment');
$mappings = new ReflectionMethod($edit, 'parseFieldMappings');
$denied = static function (callable $f): void {
    try {
        $f();
    } catch (InvalidArgumentException|RuntimeException) {
        return;
    }throw new LogicException('Invalid configuration accepted');
};
foreach (['{"type":"fixed"}','{"type":"fixed","entity_id":"10junk"}','{"type":"fixed","entity_id":true}',
'{"type":"mapped","source_field":"a","mapping":{"x":"abc"}}','{"type":"mapped","source_field":"a","mapping":{"x":10},"unknown_policy":"typo"}'] as $json) {
    $denied(fn () => $entities->invoke($edit, $json));
}
check($entities->invoke($edit, '{"type":"fixed","entity_id":10}')->resolve([]) === 10, 'Valid edition rejected');
$denied(fn () => $mappings->invoke($edit, '[{"source_field":"a","target_field":"name","transform":"arbitrary"}]'));
check(count($mappings->invoke($edit, '[{"source_field":"a","target_field":"name","transform":"trim"}]')) === 1, 'Valid mapping rejected');
foreach (["/api\r\nHost:evil",'//attacker.example/path',"/api\0x"] as $value) {
    $denied(fn () => new \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath($value));
}
echo "OK RC12 edit parser: malformed IDs/policy/transform rejected; relative resource paths enforced\n";

class Computer extends CommonDBTM
{
    public function getTable(): string
    {
        return 'glpi_computers';
    }
}
class Location extends CommonDBTM
{
    public function getTable(): string
    {
        return 'glpi_locations';
    }
}
function getItemForItemtype(string $type): CommonDBTM
{
    return new $type();
}
$scopeDb = new class () extends DBmysql {
    public function request(array $query): array
    {
        if ($query['FROM'] !== 'glpi_locations') {
            throw new LogicException('Unexpected global lookup');
        }
        check(($query['WHERE']['entities_id'] ?? null) === 10, 'Location lookup left the asset entity');
        return array_values(array_filter(CommonDBTM::$rows[Location::class] ?? [], static fn (array $row): bool => count(array_diff_assoc($query['WHERE'], $row)) === 0));
    }
};
$assetJob = SyncJob::create(
    new \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName('Asset QA'),
    new AuthConfigReference(1),
    SyncDirection::INBOUND,
    new \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath('/assets'),
    TargetItemtype::COMPUTER,
    new MatchField('name'),
    new \GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField('id'),
    entityAssignmentStrategy:EntityAssignmentStrategy::fixed(10),
    externalObjectType:'computer'
);
CommonDBTM::$rows[Computer::class][1] = ['id' => 1,'entities_id' => 10,'locations_id' => 0];
CommonDBTM::$rows[Location::class][1] = ['id' => 1,'name' => 'QA site','entities_id' => 20,'locations_id' => 0];
$links->save(ObjectLink::create(new SyncJobId(1), new AuthConfigReference(1), new ExternalId('asset1'), TargetItemtype::COMPUTER, 1, 'computer'));
$assets = new \GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\GlpiAssetReferencePostProcessor($scopeDb, $links);
$entityCount = count(CommonDBTM::$rows[Entity::class]);
check($assets->process($assetJob, ['entity' => ['name' => 'Never create this'],'location' => ['name' => 'QA site']], new ExternalId('asset1')), 'Asset reference import failed');
$locationId = CommonDBTM::$rows[Computer::class][1]['locations_id'];
check($locationId !== 1 && CommonDBTM::$rows[Location::class][$locationId]['entities_id'] === 10, 'Asset attached to foreign location');
check(count(CommonDBTM::$rows[Entity::class]) === $entityCount, 'Source entity created unexpectedly');
echo "OK RC12 asset references: source entity cannot create/move entities, location remains in asset entity\n";
