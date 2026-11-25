<?php

declare(strict_types=1);

// Database constraint and authenticator regression on a disposable SQLite fixture, not a GLPI instance.
require __DIR__ . '/bootstrap.php';

use GlpiPlugin\Uxconnector\Infrastructure\Sync\Security\WebhookReplayException;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Security\WebhookReplayStore;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Security\WebhookRequestAuthenticator;

$db = new class () extends DBmysql {
    public PDO $pdo;
    public bool $unavailable = false;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE replay (id INTEGER PRIMARY KEY AUTOINCREMENT, fingerprint CHAR(64) NOT NULL UNIQUE, expires_at BIGINT NOT NULL)');
    }

    public function insert($table, $values)
    {
        if ($this->unavailable) {
            throw new RuntimeException('Fixture unavailable.');
        }
        return $this->pdo->prepare('INSERT INTO replay (fingerprint, expires_at) VALUES (?, ?)')->execute([$values['fingerprint'], $values['expires_at']]);
    }

    public function delete($table, $where, array $joins = [])
    {
        if ($this->unavailable) {
            throw new RuntimeException('Fixture unavailable.');
        }
        return $this->pdo->prepare('DELETE FROM replay WHERE expires_at < ?')->execute([$where['expires_at'][1]]);
    }

    public function request($criteria)
    {
        $query = $this->pdo->prepare('SELECT fingerprint FROM replay WHERE fingerprint = ? LIMIT 1');
        $query->execute([$criteria['WHERE']['fingerprint']]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
};
function expectRejection(callable $call, string $type = RuntimeException::class): void
{
    try {
        $call();
    } catch (Throwable $error) {
        if ($error instanceof $type) {
            return;
        }
        throw $error;
    }
    throw new LogicException('Expected rejection: ' . $type);
}
$now = 1_794_326_400;
$secret = str_repeat('b', 64);
$key = str_repeat('a', 64);
$body = '{"id":"QA-only"}';
$load = static fn (int $id) => $id === 28 ? ['token' => $key, 'signing_secret' => $secret] : null;
$auth = WebhookRequestAuthenticator::fromEnvironment($load, (new WebhookReplayStore($db))->claim(...));
$signed = static fn (int $id, int $timestamp, string $data) => [
    'HTTP_X_UXCONNECTOR_WEBHOOK_ID' => (string) $id,
    'HTTP_X_UXCONNECTOR_TIMESTAMP' => (string) $timestamp,
    'HTTP_X_UXCONNECTOR_SIGNATURE' => hash_hmac('sha256', "$id.$timestamp.$data", $secret),
];
$headers = $signed(28, $now, $body);
if ($auth->authenticate($headers, $body, $now) !== $key) {
    throw new LogicException('Valid signature refused.');
}
expectRejection(fn () => $auth->authenticate($headers, $body, $now), WebhookReplayException::class);
$second = WebhookRequestAuthenticator::fromEnvironment($load, (new WebhookReplayStore($db))->claim(...));
$headers['HTTP_X_UXCONNECTOR_SIGNATURE'] = 'sha256=' . strtoupper($headers['HTTP_X_UXCONNECTOR_SIGNATURE']);
expectRejection(fn () => $second->authenticate($headers, $body, $now), WebhookReplayException::class);
expectRejection(fn () => $auth->authenticate($signed(28, $now, $body), 'tampered', $now));
expectRejection(fn () => $auth->authenticate($signed(28, $now - 7200, $body), $body, $now));
foreach (['0', '1', 'invalid'] as $setting) {
    putenv('UXCONNECTOR_REQUIRE_WEBHOOK_HMAC=' . $setting);
    expectRejection(fn () => $auth->authenticate(['HTTP_X_UXCONNECTOR_WEBHOOK_KEY' => $key], $body, $now));
}
if ((int) $db->pdo->query('SELECT COUNT(*) FROM replay')->fetchColumn() !== 1) {
    throw new LogicException('Unexpected replay reservation.');
}
if ((int) $db->pdo->query('SELECT id FROM replay')->fetchColumn() !== 1) {
    throw new LogicException('Numeric ID not generated.');
}
$db->unavailable = true;
expectRejection(fn () => $auth->authenticate($signed(28, $now + 1, $body), $body, $now + 1));
$db->unavailable = false;
$auth->authenticate($signed(28, $now + 1000, $body), $body, $now + 1000);
if ((int) $db->pdo->query('SELECT COUNT(*) FROM replay')->fetchColumn() !== 1) {
    throw new LogicException('Expired reservation not purged.');
}
echo "OK: numeric ID plus UNIQUE fingerprint in SQLite; valid HMAC, cross-instance replay rejection, case normalization, tampering, expiry, bearer-only refusal, storage outage and expiration cleanup. No MariaDB concurrency claim.\n";
