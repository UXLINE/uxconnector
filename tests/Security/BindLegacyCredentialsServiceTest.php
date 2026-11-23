<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use GlpiPlugin\Uxconnector\Application\Engine\Service\BindLegacyCredentialsService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\OriginBoundCredential;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\Persistence\TransactionManagerInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Security\OpenSslCredentialVault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BindLegacyCredentialsServiceTest extends TestCase
{
    private function fixture(string $failure = '', bool $nativeVault = false): array
    {
        $configs = [];
        foreach ([1, 2, 3] as $id) {
            $config = AuthConfig::create(new AuthConfigName('QA ' . $id), new Endpoint('https://source.example'), AuthMethod::BEARER_TOKEN);
            $config->assignId(new AuthConfigId($id));
            $configs[] = $config;
        }
        $repository = $this->createMock(AuthConfigRepositoryInterface::class);
        $repository->method('findAll')->willReturn($configs);
        $repository->expects(self::never())->method('save');
        $state = (object) ['rows' => [1 => 'encrypted:' . base64_encode('QA-first'), 2 => 'encrypted:' . base64_encode('QA-second')], 'writes' => 0, 'events' => []];
        if ($failure === 'wrong_binding') {
            $state->rows[2] = 'encrypted:' . base64_encode(OriginBoundCredential::wrap(
                new Endpoint('https://other.example'),
                AuthMethod::BEARER_TOKEN,
                'QA-second',
            ));
        }
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturnCallback(static function (string $value) use ($failure): string {
            $plain = str_starts_with($value, 'encrypted:') ? base64_decode(substr($value, 10), true) : false;
            if ($plain === false || ($failure === 'decrypt' && $plain === 'QA-second')) {
                throw new \RuntimeException('Vault error containing QA-secret-must-not-leak');
            }
            return $plain;
        });
        $vault->method('protect')->willReturnCallback(static function (string $value) use ($failure): string {
            if ($failure === 'plaintext') {
                return $value;
            }
            return 'encrypted:' . base64_encode($failure === 'encrypt_corruption' ? 'wrong' : $value);
        });
        if ($nativeVault) {
            $vault = new OpenSslCredentialVault(random_bytes(32), new \GLPIKey(sys_get_temp_dir()));
            foreach ($state->rows as &$value) {
                $value = $vault->protect(base64_decode(substr($value, 10), true));
            }
            unset($value);
        }
        $original = $state->rows;
        $store = $this->createMock(AuthConfigSecretStoreInterface::class);
        $store->method('get')->willReturnCallback(static fn (AuthConfigId $id): ?string => $state->rows[$id->value()] ?? null);
        $store->method('put')->willReturnCallback(static function (AuthConfigId $id, string $value) use ($state, $failure): void {
            if ($failure === 'write' && $id->value() === 2) {
                throw new \RuntimeException('Storage unavailable');
            }
            $state->rows[$id->value()] = $failure === 'storage_corruption' ? 'encrypted:' . base64_encode('wrong') : $value;
            ++$state->writes;
        });
        $transaction = $this->createMock(TransactionManagerInterface::class);
        $transaction->method('begin')->willReturnCallback(static function () use ($state): void {
            $state->events[] = 'begin';
        });
        $transaction->method('commit')->willReturnCallback(static function () use ($state, $failure): void {
            if ($failure === 'commit') {
                throw new \RuntimeException('Commit failed');
            }
            $state->events[] = 'commit';
        });
        $transaction->method('rollback')->willReturnCallback(static function () use ($state, $original): void {
            $state->rows = $original;
            $state->events[] = 'rollback';
        });
        return [new BindLegacyCredentialsService($repository, $store, $vault, $transaction), $state, $original, $vault];
    }

    public function testMigrationPreservesSecretsAndIsIdempotent(): void
    {
        [$service, $state, , $vault] = $this->fixture();
        $service->execute();
        foreach ([1 => 'QA-first', 2 => 'QA-second'] as $id => $plain) {
            self::assertSame($plain, OriginBoundCredential::unwrap(
                new Endpoint('https://source.example'),
                AuthMethod::BEARER_TOKEN,
                $vault->reveal($state->rows[$id]),
            ));
        }
        $firstPass = $state->rows;
        $service->execute();
        self::assertSame($firstPass, $state->rows);
        self::assertSame(2, $state->writes);
        self::assertSame(['begin', 'commit', 'begin', 'commit'], $state->events);
    }

    public function testMigrationWithNativeGlpiKeyEncryption(): void
    {
        if (!getenv('GLPI_TEST_SOURCE') || !function_exists('Safe\\sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            self::markTestSkipped('GLPI sources and thecodingmachine/safe required.');
        }
        [$service, $state, $original, $vault] = $this->fixture(nativeVault: true);
        $service->execute();
        self::assertNotSame($original, $state->rows);
        foreach ([1 => 'QA-first', 2 => 'QA-second'] as $id => $secret) {
            self::assertStringNotContainsString($secret, $state->rows[$id]);
            self::assertSame($secret, OriginBoundCredential::unwrap(
                new Endpoint('https://source.example'),
                AuthMethod::BEARER_TOKEN,
                $vault->reveal($state->rows[$id]),
            ));
        }
        $migrated = $state->rows;
        $service->execute();
        self::assertSame($migrated, $state->rows);
    }

    public static function failures(): iterable
    {
        foreach (['decrypt', 'plaintext', 'encrypt_corruption', 'storage_corruption', 'write', 'commit', 'wrong_binding'] as $failure) {
            yield $failure => [$failure];
        }
    }

    #[DataProvider('failures')]
    public function testFailureRollsBackWithoutExposingSecrets(string $failure): void
    {
        [$service, $state, $original] = $this->fixture($failure);
        try {
            $service->execute();
            self::fail('The migration must fail closed.');
        } catch (\RuntimeException $error) {
            self::assertStringStartsWith('Unable to bind connection credentials.', $error->getMessage());
            self::assertStringNotContainsString('QA-', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertSame($original, $state->rows);
            self::assertSame(['begin', 'rollback'], $state->events);
        }
    }
}
