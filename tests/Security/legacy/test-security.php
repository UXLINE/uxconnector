<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Application\Engine\Command\UpdateAuthConfigCommand;
use GlpiPlugin\Uxconnector\Application\Engine\Service\UpdateAuthConfigService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\OutboundRuntimeValueResolver;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\OriginBoundCredential;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\OutboundConfig;

spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'GlpiPlugin\\Uxconnector\\';
    if (str_starts_with($class, $prefix)) {
        require $argv[1] . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
function check(bool $ok): void
{
    if (!$ok) {
        throw new LogicException('Assertion failed');
    }
}
function refuses(callable $test, string $exception): void
{
    try {
        $test();
    } catch (Throwable $error) {
        check($error instanceof $exception);
        return;
    }
    throw new LogicException('Expected rejection');
}

const ERROR = 2;
const INFO = 0;
function htmlescape(mixed $value): string
{
    return htmlspecialchars((string) $value);
}
function __(string $value, string $domain = ''): string
{
    return $value;
}
class Session
{
    public static array $messages = [];
    public static function addMessageAfterRedirect(string $message, bool $unused = false, int $level = 0): void
    {
        self::$messages[] = $message;
    }
}
class Html
{
    public static function redirect(string $url): void
    {
    }
}

$scenario = $argv[2] ?? '';
if (in_array($scenario, ['origine', 'notifications'], true)) {
    $name = new AuthConfigName('QA endpoint');
    $id = new AuthConfigId(1);
    $method = AuthMethod::BEARER_TOKEN;
    $original = new Endpoint('https://api.example.com/v1');
    $account = AuthConfig::create($name, $original, $method);
    $account->assignId($id);
    $repo = new class ($account) implements AuthConfigRepositoryInterface {
        public function __construct(public AuthConfig $record)
        {
        }
        public function save(AuthConfig $authConfig): AuthConfig
        {
            return $this->record = $authConfig;
        }
        public function findById(AuthConfigId $id): ?AuthConfig
        {
            return $this->record;
        }
        public function findAll(): array
        {
            return [$this->record];
        }
        public function delete(AuthConfigId $id): void
        {
        }
        public function exists(AuthConfigId $id): bool
        {
            return true;
        }
    };
    $secrets = new class () implements AuthConfigSecretStoreInterface {
        public ?string $value = 'legacy-fake-token';
        public function put(AuthConfigId $id, string $protectedSecret): void
        {
            $this->value = $protectedSecret;
        }
        public function get(AuthConfigId $id): ?string
        {
            return $this->value;
        }
        public function delete(AuthConfigId $id): void
        {
            $this->value = null;
        }
    };
    $vault = new class () implements CredentialVaultInterface {
        public function protect(string $plaintextSecret): string
        {
            return $plaintextSecret;
        }
        public function reveal(string $protectedSecret): string
        {
            return $protectedSecret;
        }
    };
    $policy = new class () implements DestinationPolicyInterface {
        public function assertAllowed(Endpoint $endpoint): void
        {
        } public function resolveAllowed(Endpoint $endpoint): array
        {
            return ['127.0.0.1'];
        }
    };
    $tx = new class () implements TransactionManagerInterface {
        public int $started = 0;
        public function begin(): void
        {
            ++$this->started;
        }
        public function commit(): void
        {
        }
        public function rollback(): void
        {
        }
    };
    $update = new UpdateAuthConfigService($repo, $secrets, $vault, $policy, $tx);
    $submit = static fn (string $endpoint, ?string $secret = null, AuthMethod $auth = AuthMethod::BEARER_TOKEN)
        => $update->execute(new UpdateAuthConfigCommand($id, $name, new Endpoint($endpoint), $auth, new TestPath('/ping'), $secret));
}

if ($scenario === 'origine') {
    foreach (['https://new.example.com/v1','https://api.example.com:444/v1'] as $endpoint) {
        refuses(static fn () => $submit($endpoint), InvalidArgumentException::class);
    }
    refuses(
        static fn () => $submit('http://api.example.com/v1'),
        GlpiPlugin\Uxconnector\Domain\Engine\Exception\InsecureCredentialTransportException::class,
    );
    check($tx->started === 0 && $repo->record->endpoint()->equals($original));
    $submit('https://API.EXAMPLE.COM:443/v2');
    check($secrets->value === 'legacy-fake-token');
    $target = new Endpoint('https://new.example.com/v1');
    $submit($target->value(), 'new-fake-token');
    check(OriginBoundCredential::unwrap($target, $method, $secrets->value) === 'new-fake-token');
    refuses(static fn () => OriginBoundCredential::unwrap($original, $method, $secrets->value), RuntimeException::class);
    refuses(static fn () => OriginBoundCredential::unwrap($target, AuthMethod::BASIC, $secrets->value), RuntimeException::class);
    check(OriginBoundCredential::unwrap($original, $method, 'legacy-fake-token') === 'legacy-fake-token');
    echo "OK origin change, explicit default port, legacy credential and runtime binding\n";

    exit(0);
}
if ($scenario === 'sortant') {
    $resolver = new OutboundRuntimeValueResolver();
    foreach (['password', 'password_history', 'password_forget_token', 'api_token', '2fa', 'private_key'] as $field) {
        $fields = [$field => 'sentinel', 'name' => 'QA public name'];
        refuses(static fn () => $resolver->resolveBody(['field' => '@field:' . $field], $fields), InvalidArgumentException::class);
        refuses(static fn () => $resolver->resolveBody('@field_optional:' . $field, []), InvalidArgumentException::class);
        refuses(static fn () => $resolver->resolvePath('/{{field.' . $field . '}}', $fields, 1, null), InvalidArgumentException::class);
        $body = OutboundConfig::fromArray(['create' => ['method' => 'POST', 'path' => '/safe', 'body' => ['nested' => ['@field:' . $field]]]]);
        $path = OutboundConfig::fromArray(['update' => ['method' => 'PUT', 'path' => '/{{field.' . $field . '}}']]);
        refuses(static fn () => $resolver->assertConfiguration($body), InvalidArgumentException::class);
        refuses(static fn () => $resolver->assertConfiguration($path), InvalidArgumentException::class);
    }
    $body = OutboundConfig::fromArray(['create' => ['method' => 'POST', 'path' => '/{{field.name}}', 'body' => ['name' => '@field:name']]]);
    $resolver->assertConfiguration($body);
    check($resolver->resolveBody($body->createRequest['body'], ['name' => 'QA public name']) === ['name' => 'QA public name']);
    check($resolver->resolvePath('/{{field.name}}', ['name' => 'QA public name'], 1, null) === '/QA%20public%20name');
    echo "OK outbound create/update configuration and dispatch, required and optional fields, URL and body\n";

    exit(0);
}
if ($scenario === 'api-webhook') {
    class CommonDBTM
    {
        public static $undisclosedFields = [];
    }
    check(GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncJobItem::$undisclosedFields === [
            'webhook_key', 'webhook_key_hash', 'webhook_token_secret', 'webhook_signing_secret',
        ]);
    echo "OK webhook key declared as undisclosed\n";
    exit(0);
}

if ($scenario === 'notifications') {
    $engine = new class () implements \GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface {
        public bool $throws = false;
        public function execute(
            AuthConfig $authConfig,
            \GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest $request
        ): \GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse {
            if ($this->throws) {
                throw new RuntimeException('Transport: <b>TECLIB-QA</b>');
            }
            return new \GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse(404, [], '');
        }
    };
    $service = new \GlpiPlugin\Uxconnector\Application\Engine\Service\TestAuthConfigService($repo, $engine);
    foreach ([
        \GlpiPlugin\Uxconnector\Presentation\Connection\Controller\ConnectionController::class => 'testConnection',
        \GlpiPlugin\Uxconnector\Presentation\Engine\Controller\AuthConfigController::class => 'testAuthConfig',
    ] as $class => $property) {
        $reflection = new ReflectionClass($class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty($property)->setValue($controller, $service);
        foreach ([false, true] as $throws) {
            $repo->record->changeTestPath(new TestPath('/<b>TECLIB-QA</b>'));
            $engine->throws = $throws;
            Session::$messages = [];
            $controller->test(['id' => 1]);
            check(count(Session::$messages) === 1);
            check(str_contains(Session::$messages[0], '&lt;b&gt;TECLIB-QA&lt;/b&gt;'));
            check(!str_contains(Session::$messages[0], '<b>TECLIB-QA</b>'));
        }
    }
    echo "OK both controllers escape the HTTP 404 and transport exception notifications\n";
    exit(0);
}
throw new InvalidArgumentException('Unknown scenario');
