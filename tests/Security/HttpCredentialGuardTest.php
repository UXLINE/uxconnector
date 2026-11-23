<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigSecretStoreInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\CredentialVaultInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\OriginBoundCredential;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\AuthenticationStrategyFactory;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\OAuth2TokenClient;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\GenericHttpEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpCredentialGuardTest extends TestCase
{
    public static function unusableSecrets(): iterable
    {
        yield 'legacy' => ['QA-legacy-secret'];
        yield 'different origin' => [OriginBoundCredential::wrap(
            new Endpoint('https://source.example'),
            AuthMethod::BEARER_TOKEN,
            'QA-bound-secret',
        )];
    }

    #[DataProvider('unusableSecrets')]
    public function testRewrittenEndpointCannotReachTransportWithExistingSecret(string $secret): void
    {
        $config = AuthConfig::create(new AuthConfigName('QA'), new Endpoint('https://attacker.example'), AuthMethod::BEARER_TOKEN);
        $config->assignId(new AuthConfigId(1));
        $store = $this->createMock(AuthConfigSecretStoreInterface::class);
        $store->method('get')->willReturn('encrypted');
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturn($secret);
        $policy = $this->createMock(DestinationPolicyInterface::class);
        $policy->expects(self::once())->method('assertAllowed');
        // Both transport implementations resolve the pinned destination before opening a connection.
        $policy->expects(self::never())->method('resolveAllowed');
        $engine = new GenericHttpEngine($store, $vault, new AuthenticationStrategyFactory(new OAuth2TokenClient($policy), $policy), $policy);
        $this->expectException(\RuntimeException::class);
        $engine->execute($config, new HttpRequest(HttpMethod::GET, '/api'));
    }
}
