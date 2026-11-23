<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\OriginBoundCredential;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence\AuthConfigItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginBoundCredentialTest extends TestCase
{
    public function testSameOriginMayUseAnotherResourcePath(): void
    {
        $secret = '{"password":"QA-only\nvalue","token_url":"https://source.example/token"}';
        $bound = OriginBoundCredential::wrap(new Endpoint('https://source.example/api'), AuthMethod::OAUTH2_PASSWORD, $secret);
        self::assertSame($secret, OriginBoundCredential::unwrap(
            new Endpoint('https://source.example/other'),
            AuthMethod::OAUTH2_PASSWORD,
            $bound,
        ));
    }

    public static function rejectedBindings(): iterable
    {
        yield 'legacy' => ['https://source.example', AuthMethod::BEARER_TOKEN, 'legacy-secret'];
        $bound = OriginBoundCredential::wrap(new Endpoint('https://source.example'), AuthMethod::BEARER_TOKEN, 'QA-only');
        yield 'host' => ['https://attacker.example', AuthMethod::BEARER_TOKEN, $bound];
        yield 'port' => ['https://source.example:8443', AuthMethod::BEARER_TOKEN, $bound];
        yield 'protocol' => ['http://source.example', AuthMethod::BEARER_TOKEN, $bound];
        yield 'method' => ['https://source.example', AuthMethod::API_KEY, $bound];
        yield 'truncated' => ['https://source.example', AuthMethod::BEARER_TOKEN, "UXC-CREDENTIAL-ORIGIN-1\n"];
    }

    #[DataProvider('rejectedBindings')]
    public function testUnboundOrMismatchedSecretsAreUnusable(string $endpoint, AuthMethod $method, string $stored): void
    {
        $this->expectException(\RuntimeException::class);
        OriginBoundCredential::unwrap(new Endpoint($endpoint), $method, $stored);
    }

    public function testCoreApiCannotMutateEvenWhenParentGrantsAllRights(): void
    {
        \CommonDBTM::$allow = true;
        $item = new AuthConfigItem();
        foreach ([CREATE, UPDATE, DELETE, PURGE, READ | UPDATE, CREATE | PURGE] as $right) {
            self::assertFalse($item->can(1, $right));
        }
        self::assertTrue($item->can(1, READ));
        \CommonDBTM::$allow = false;
        self::assertFalse($item->can(1, READ));
    }
}
