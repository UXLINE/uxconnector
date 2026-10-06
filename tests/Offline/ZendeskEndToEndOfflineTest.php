<?php

declare(strict_types=1);

namespace {
    if (!defined('GLPI_TMP_DIR')) {
        define('GLPI_TMP_DIR', sys_get_temp_dir() . '/uxconnector-zendesk-offline');
    }
    if (!is_dir(GLPI_TMP_DIR)) {
        mkdir(GLPI_TMP_DIR, 0777, true);
    }

    final class UxconnectorOfflineGlpiStore
    {
        /** @var array<string, array<int, array<string,mixed>>> */
        public static array $tables = [];
        /** @var array<string, int> */
        public static array $nextIds = [];

        public static function reset(): void
        {
            self::$tables = [];
            self::$nextIds = [];
            foreach (glob(GLPI_TMP_DIR . '/uxconnector-zendesk-*') ?: [] as $path) {
                @unlink($path);
            }
        }

        /** @param array<string,mixed> $row */
        public static function seed(string $class, int $id, array $row): void
        {
            $row['id'] = $id;
            self::$tables[$class][$id] = $row;
            self::$nextIds[$class] = max(self::$nextIds[$class] ?? 1, $id + 1);
        }

        /** @param array<string,mixed> $row */
        public static function add(string $class, array $row): int
        {
            $id = self::$nextIds[$class] ?? 1;
            self::$nextIds[$class] = $id + 1;
            $row['id'] = $id;
            self::$tables[$class][$id] = $row;
            return $id;
        }

        /** @return array<int,array<string,mixed>> */
        public static function rows(string $class): array
        {
            return self::$tables[$class] ?? [];
        }
    }

    if (!class_exists('CommonDBTM', false)) {
        define('UXCONNECTOR_OFFLINE_GLPI_STUBS', true);

        class CommonDBTM
        {
            /** @var array<string,mixed> */
            public array $fields = [];

            public function getFromDB(int $id): bool
            {
                $row = UxconnectorOfflineGlpiStore::$tables[static::class][$id] ?? null;
                if ($row === null) {
                    return false;
                }
                $this->fields = $row;
                return true;
            }

            /** @param array<string,mixed> $criteria */
            public function getFromDBByCrit(array $criteria): bool
            {
                foreach (UxconnectorOfflineGlpiStore::rows(static::class) as $row) {
                    if ($this->matches($row, $criteria)) {
                        $this->fields = $row;
                        return true;
                    }
                }
                return false;
            }

            public function getID(): int
            {
                return (int) ($this->fields['id'] ?? 0);
            }

            /** @param array<string,mixed> $input */
            public function add(array $input): int|false
            {
                $id = UxconnectorOfflineGlpiStore::add(static::class, $this->cleanInput($input));
                $this->fields = UxconnectorOfflineGlpiStore::$tables[static::class][$id];
                return $id;
            }

            /** @param array<string,mixed> $input */
            public function update(array $input): bool
            {
                $id = (int) ($input['id'] ?? $this->fields['id'] ?? 0);
                if ($id <= 0 || !isset(UxconnectorOfflineGlpiStore::$tables[static::class][$id])) {
                    return false;
                }
                $row = UxconnectorOfflineGlpiStore::$tables[static::class][$id];
                foreach ($this->cleanInput($input) as $key => $value) {
                    if ($key !== 'id') {
                        $row[$key] = $value;
                    }
                }
                UxconnectorOfflineGlpiStore::$tables[static::class][$id] = $row;
                $this->fields = $row;
                return true;
            }

            /** @param list<string> $fields @param array<string,mixed> $oldValues */
            public function updateInDB(array $fields, array $oldValues = []): bool
            {
                $id = $this->getID();
                if ($id <= 0 || !isset(UxconnectorOfflineGlpiStore::$tables[static::class][$id])) {
                    return false;
                }
                foreach ($fields as $field) {
                    UxconnectorOfflineGlpiStore::$tables[static::class][$id][$field] = $this->fields[$field] ?? null;
                }
                return true;
            }

            /**
             * @param array<string,mixed> $criteria
             * @param array<int|string,mixed> $order
             * @return array<int,array<string,mixed>>
             */
            public function find(array $criteria = [], array $order = [], int $limit = 0): array
            {
                $found = [];
                foreach (UxconnectorOfflineGlpiStore::rows(static::class) as $id => $row) {
                    if ($this->matches($row, $criteria)) {
                        $found[$id] = $row;
                        if ($limit > 0 && count($found) >= $limit) {
                            break;
                        }
                    }
                }
                return $found;
            }

            /**
             * Minimal GLPI addFiles contract used by ZendeskTicketPostProcessor.
             * @param array<string,mixed> $input
             * @param array<string,mixed> $options
             * @return array<string,mixed>
             */
            public function addFiles(array $input, array $options = []): array
            {
                $filenames = isset($input['_filename']) && is_array($input['_filename']) ? $input['_filename'] : [];
                $tags = isset($input['_tag_filename']) && is_array($input['_tag_filename']) ? $input['_tag_filename'] : [];
                foreach ($filenames as $index => $filename) {
                    $tag = (string) ($tags[$index] ?? '');
                    UxconnectorOfflineGlpiStore::add(Document::class, [
                        'name' => basename((string) $filename),
                        'filename' => basename((string) $filename),
                        'tag' => $tag,
                        'mime' => str_ends_with(strtolower((string) $filename), '.png') ? 'image/png' : 'application/octet-stream',
                        'itemtype' => static::class,
                        'items_id' => $this->getID(),
                    ]);
                }

                return ['content' => (string) ($input['content'] ?? ($this->fields['content'] ?? ''))];
            }

            /** @param array<string,mixed> $row @param array<string,mixed> $criteria */
            private function matches(array $row, array $criteria): bool
            {
                foreach ($criteria as $key => $value) {
                    if (($row[$key] ?? null) !== $value) {
                        return false;
                    }
                }
                return true;
            }

            /** @param array<string,mixed> $input @return array<string,mixed> */
            private function cleanInput(array $input): array
            {
                foreach (array_keys($input) as $key) {
                    if (str_starts_with((string) $key, '_')) {
                        unset($input[$key]);
                    }
                }
                return $input;
            }
        }

        class Ticket extends CommonDBTM {}
        class Ticket_User extends CommonDBTM {}
        class Group_Ticket extends CommonDBTM {}
        class ITILFollowup extends CommonDBTM {}
        class ITILCategory extends CommonDBTM {}

        class Document extends CommonDBTM
        {
            public static function getImageTag(string $tag): string
            {
                return '##document:' . $tag . '##';
            }
        }

        final class Toolbox
        {
            /** @param array<int,array<string,mixed>> $documents */
            public static function convertTagToImage(string $content, CommonDBTM $item, array $documents): string
            {
                foreach ($documents as $id => $document) {
                    $tag = (string) ($document['tag'] ?? '');
                    if ($tag !== '') {
                        $content = str_replace(Document::getImageTag($tag), '<img data-document-id="' . (int) $id . '">', $content);
                    }
                }
                return $content;
            }
        }
    }
}

namespace GlpiPlugin\Uxconnector\Tests\Offline {
    use DateTimeImmutable;
    use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
    use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthConfigStatus;
    use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
    use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
    use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
    use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;
    use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
    use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
    use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
    use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
    use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
    use GlpiPlugin\Uxconnector\Domain\Sync\Entity\ObjectLink;
    use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
    use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
    use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
    use GlpiPlugin\Uxconnector\Domain\Sync\Repository\ObjectLinkRepositoryInterface;
    use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
    use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalId;
    use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
    use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
    use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
    use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ObjectLinkId;
    use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
    use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
    use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
    use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskTicketPostProcessor;
    use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskTopicCatalog;
    use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskTopicCategoryService;
    use PHPUnit\Framework\TestCase;

    final class ZendeskEndToEndOfflineTest extends TestCase
    {
        private string $fixtureDir;

        protected function setUp(): void
        {
            if (!defined('UXCONNECTOR_OFFLINE_GLPI_STUBS')) {
                self::markTestSkipped('ZendeskOffline must run without GLPI_ROOT / a booted GLPI runtime.');
            }
            \UxconnectorOfflineGlpiStore::reset();
            $this->fixtureDir = dirname(__DIR__) . '/Fixtures/Zendesk';
            \UxconnectorOfflineGlpiStore::seed(\Ticket::class, 7001, [
                'name' => 'VPN inaccessible',
                'content' => '<p>Le VPN ne fonctionne plus.</p>',
                'entities_id' => 0,
                'itilcategories_id' => 0,
            ]);
        }

        public function testFullTicketEnrichmentAndReplayAreOfflineAndIdempotent(): void
        {
            $auth = AuthConfig::reconstitute(
                new AuthConfigId(1),
                new AuthConfigName('Zendesk offline fixture'),
                new Endpoint('https://zendesk.invalid'),
                AuthMethod::NONE,
                AuthConfigStatus::ACTIVE,
                new DateTimeImmutable('2026-09-01 00:00:00'),
                new DateTimeImmutable('2026-09-01 00:00:00'),
                connectorId: 'zendesk'
            );
            $authRepo = new OfflineAuthConfigRepository($auth);
            $links = new OfflineObjectLinkRepository();
            $http = new OfflineZendeskHttpEngine($this->fixtureDir);
            $policy = new OfflineDestinationPolicy();

            $job = SyncJob::create(
                new SyncJobName('Zendesk tickets offline regression'),
                new AuthConfigReference(1),
                SyncDirection::INBOUND,
                new ResourcePath('/api/v2/tickets.json'),
                TargetItemtype::TICKET,
                new MatchField('name'),
                new ExternalIdField('id'),
                [new FieldMapping('subject', 'name', required: true)],
                templateId: 'zendesk.tickets.inbound',
                templateVersion: '2.18.3',
                externalObjectType: 'zendesk_ticket'
            );
            $job->assignId(new SyncJobId(1));

            $this->seedLink($links, $job, 'zendesk_ticket', '123', TargetItemtype::TICKET, 7001);
            $this->seedLink($links, $job, 'zendesk_user', '101', TargetItemtype::USER, 5001);
            $this->seedLink($links, $job, 'zendesk_user', '102', TargetItemtype::USER, 5002);
            $this->seedLink($links, $job, 'zendesk_group', '201', TargetItemtype::GROUP, 6001);
            $this->seedLink($links, $job, 'zendesk_organization', '301', TargetItemtype::ENTITY, 42);

            $processor = new ZendeskTicketPostProcessor(
                $links,
                $authRepo,
                $http,
                $policy,
                new ZendeskTopicCatalog($authRepo, $http),
                new ZendeskTopicCategoryService($links)
            );

            /** @var array<string,mixed> $ticket */
            $ticket = json_decode((string) file_get_contents($this->fixtureDir . '/ticket.json'), true, flags: JSON_THROW_ON_ERROR);

            self::assertTrue($processor->supports($job));
            self::assertTrue($processor->process($job, $ticket, new ExternalId('123')));

            $ticketRow = \UxconnectorOfflineGlpiStore::$tables[\Ticket::class][7001];
            self::assertSame(42, $ticketRow['entities_id']);
            self::assertGreaterThan(0, (int) $ticketRow['itilcategories_id']);

            $categories = \UxconnectorOfflineGlpiStore::rows(\ITILCategory::class);
            self::assertCount(2, $categories, 'IT :: Accès must create exactly parent + leaf categories.');
            self::assertSame(['IT', 'Accès'], array_values(array_column($categories, 'name')));

            self::assertCount(2, \UxconnectorOfflineGlpiStore::rows(\Ticket_User::class));
            self::assertCount(1, \UxconnectorOfflineGlpiStore::rows(\Group_Ticket::class));

            $followups = \UxconnectorOfflineGlpiStore::rows(\ITILFollowup::class);
            self::assertCount(2, $followups, 'Initial Zendesk description must not be duplicated as a followup.');
            $privacy = array_values(array_column($followups, 'is_private'));
            sort($privacy);
            self::assertSame([0, 1], $privacy, 'Public/private Zendesk comments must be preserved.');

            $documents = \UxconnectorOfflineGlpiStore::rows(\Document::class);
            self::assertCount(2, $documents, 'Ticket attachment + inline followup attachment must both be imported.');
            self::assertNotNull($links->findByExternalId(new AuthConfigReference(1), 'zendesk_attachment', new ExternalId('9001')));
            self::assertNotNull($links->findByExternalId(new AuthConfigReference(1), 'zendesk_attachment', new ExternalId('9002')));
            self::assertNotNull($links->findByExternalId(new AuthConfigReference(1), 'zendesk_comment', new ExternalId('5002')));
            self::assertNotNull($links->findByExternalId(new AuthConfigReference(1), 'zendesk_comment', new ExternalId('5003')));

            $publicFollowup = array_values(array_filter($followups, static fn (array $row): bool => (int) ($row['is_private'] ?? 0) === 0))[0];
            self::assertStringContainsString('data-document-id=', (string) ($publicFollowup['content'] ?? ''), 'Inline image must be converted to a local GLPI document reference.');
            self::assertStringNotContainsString('zendesk.invalid', (string) ($publicFollowup['content'] ?? ''), 'Zendesk attachment URL must not remain embedded in GLPI followup content.');

            $snapshot = [
                'categories' => count($categories),
                'ticket_users' => count(\UxconnectorOfflineGlpiStore::rows(\Ticket_User::class)),
                'groups' => count(\UxconnectorOfflineGlpiStore::rows(\Group_Ticket::class)),
                'followups' => count($followups),
                'documents' => count($documents),
                'links' => $links->count(),
            ];

            // Replay the exact same Zendesk ticket: no duplicate business objects.
            self::assertTrue($processor->process($job, $ticket, new ExternalId('123')));
            self::assertSame($snapshot['categories'], count(\UxconnectorOfflineGlpiStore::rows(\ITILCategory::class)));
            self::assertSame($snapshot['ticket_users'], count(\UxconnectorOfflineGlpiStore::rows(\Ticket_User::class)));
            self::assertSame($snapshot['groups'], count(\UxconnectorOfflineGlpiStore::rows(\Group_Ticket::class)));
            self::assertSame($snapshot['followups'], count(\UxconnectorOfflineGlpiStore::rows(\ITILFollowup::class)));
            self::assertSame($snapshot['documents'], count(\UxconnectorOfflineGlpiStore::rows(\Document::class)));
            self::assertSame($snapshot['links'], $links->count());
        }


        public function testMissingOrganizationDoesNotBlockCategoryCommentsOrAttachments(): void
        {
            $auth = AuthConfig::reconstitute(
                new AuthConfigId(1),
                new AuthConfigName('Zendesk offline fixture'),
                new Endpoint('https://zendesk.invalid'),
                AuthMethod::NONE,
                AuthConfigStatus::ACTIVE,
                new DateTimeImmutable('2026-09-01 00:00:00'),
                new DateTimeImmutable('2026-09-01 00:00:00'),
                connectorId: 'zendesk'
            );
            $authRepo = new OfflineAuthConfigRepository($auth);
            $links = new OfflineObjectLinkRepository();
            $http = new OfflineZendeskHttpEngine($this->fixtureDir);
            $job = SyncJob::create(
                new SyncJobName('Zendesk partial relation regression'),
                new AuthConfigReference(1),
                SyncDirection::INBOUND,
                new ResourcePath('/api/v2/tickets.json'),
                TargetItemtype::TICKET,
                new MatchField('name'),
                new ExternalIdField('id'),
                [new FieldMapping('subject', 'name', required: true)],
                templateId: 'zendesk.tickets.inbound',
                templateVersion: '2.18.3',
                externalObjectType: 'zendesk_ticket'
            );
            $job->assignId(new SyncJobId(2));

            $this->seedLink($links, $job, 'zendesk_ticket', '123', TargetItemtype::TICKET, 7001);
            $this->seedLink($links, $job, 'zendesk_user', '101', TargetItemtype::USER, 5001);
            $this->seedLink($links, $job, 'zendesk_user', '102', TargetItemtype::USER, 5002);
            $this->seedLink($links, $job, 'zendesk_group', '201', TargetItemtype::GROUP, 6001);
            // Deliberately no zendesk_organization link: this is the exact
            // regression that used to abort category/comments/attachments.

            $processor = new ZendeskTicketPostProcessor(
                $links,
                $authRepo,
                $http,
                new OfflineDestinationPolicy(),
                new ZendeskTopicCatalog($authRepo, $http),
                new ZendeskTopicCategoryService($links)
            );

            /** @var array<string,mixed> $ticket */
            $ticket = json_decode((string) file_get_contents($this->fixtureDir . '/ticket.json'), true, flags: JSON_THROW_ON_ERROR);

            self::assertFalse($processor->process($job, $ticket, new ExternalId('123')), 'Missing organization must keep the record retryable.');
            self::assertGreaterThan(0, (int) (\UxconnectorOfflineGlpiStore::$tables[\Ticket::class][7001]['itilcategories_id'] ?? 0));
            self::assertCount(2, \UxconnectorOfflineGlpiStore::rows(\ITILFollowup::class), 'Comments must still be imported.');
            self::assertCount(2, \UxconnectorOfflineGlpiStore::rows(\Document::class), 'Attachments must still be imported.');
        }

        private function seedLink(
            OfflineObjectLinkRepository $links,
            SyncJob $job,
            string $externalType,
            string $externalId,
            TargetItemtype $itemtype,
            int $itemId
        ): void {
            $links->save(ObjectLink::create(
                $job->id() ?? throw new \LogicException('Job id missing.'),
                $job->authConfigReference(),
                new ExternalId($externalId),
                $itemtype,
                $itemId,
                $externalType
            ));
        }
    }

    final class OfflineObjectLinkRepository implements ObjectLinkRepositoryInterface
    {
        /** @var array<int,ObjectLink> */
        private array $items = [];
        private int $nextId = 1;

        public function save(ObjectLink $objectLink): ObjectLink
        {
            if ($objectLink->id() === null) {
                $objectLink->assignId(new ObjectLinkId($this->nextId++));
            }
            $this->items[$objectLink->id()->value()] = $objectLink;
            return $objectLink;
        }

        public function findByExternalId(AuthConfigReference $authConfigReference, string $externalObjectType, ExternalId $externalId): ?ObjectLink
        {
            foreach ($this->items as $item) {
                if ($item->authConfigReference()->equals($authConfigReference)
                    && $item->externalObjectType() === $externalObjectType
                    && $item->externalId()->value() === $externalId->value()) {
                    return $item;
                }
            }
            return null;
        }

        public function findByGlpiItem(AuthConfigReference $authConfigReference, TargetItemtype $itemtype, int $itemId, ?string $externalObjectType = null): ?ObjectLink
        {
            foreach ($this->items as $item) {
                if ($item->authConfigReference()->equals($authConfigReference)
                    && $item->itemtype() === $itemtype
                    && $item->itemId() === $itemId
                    && ($externalObjectType === null || $item->externalObjectType() === $externalObjectType)) {
                    return $item;
                }
            }
            return null;
        }

        public function delete(ObjectLinkId $id): void
        {
            unset($this->items[$id->value()]);
        }

        public function count(): int
        {
            return count($this->items);
        }
    }

    final class OfflineAuthConfigRepository implements AuthConfigRepositoryInterface
    {
        public function __construct(private AuthConfig $auth) {}
        public function save(AuthConfig $authConfig): AuthConfig { $this->auth = $authConfig; return $authConfig; }
        public function findById(AuthConfigId $id): ?AuthConfig { return $this->auth->id()?->equals($id) ? $this->auth : null; }
        public function findAll(): array { return [$this->auth]; }
        public function delete(AuthConfigId $id): void {}
        public function exists(AuthConfigId $id): bool { return $this->findById($id) !== null; }
    }

    final class OfflineDestinationPolicy implements DestinationPolicyInterface
    {
        public function assertAllowed(Endpoint $endpoint): void
        {
            // Keep the regression suite strictly offline: force the direct URL
            // downloader to stop before fopen(), then exercise the authenticated
            // same-origin fallback through OfflineZendeskHttpEngine.
            throw new \RuntimeException('Offline fixture: direct network disabled.');
        }
    }

    final class OfflineZendeskHttpEngine implements HttpEngineInterface
    {
        public function __construct(private readonly string $fixtureDir) {}

        public function execute(AuthConfig $authConfig, HttpRequest $request): HttpResponse
        {
            $path = $request->path;
            if ($path === '/api/v2/ticket_fields.json?per_page=100') {
                return $this->json('ticket_fields.json');
            }
            if ($path === '/api/v2/tickets/123/comments.json?per_page=100&include=users&include_inline_images=true') {
                return $this->json('comments.json');
            }
            if (str_starts_with($path, '/api/v2/attachments/9001/content')) {
                return new HttpResponse(200, ['Content-Type' => 'image/png'], (string) file_get_contents($this->fixtureDir . '/attachment-9001.bin'));
            }
            if (str_starts_with($path, '/api/v2/attachments/9002/content')) {
                return new HttpResponse(200, ['Content-Type' => 'image/png'], (string) file_get_contents($this->fixtureDir . '/attachment-9002.bin'));
            }

            return new HttpResponse(404, [], '{"error":"fixture_not_found"}');
        }

        private function json(string $file): HttpResponse
        {
            return new HttpResponse(200, ['Content-Type' => 'application/json'], (string) file_get_contents($this->fixtureDir . '/' . $file));
        }
    }
}
