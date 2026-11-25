<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Security;

use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security\PinnedHttpDestination;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security\PinnedStreamOpener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PinnedStreamOpenerTest extends TestCase
{
    private function destination(): PinnedHttpDestination
    {
        $policy = $this->createMock(DestinationPolicyInterface::class);
        $policy->expects(self::once())->method('resolveAllowed')->willReturn(['2001:db8::1', '192.0.2.1', '192.0.2.1']);
        return PinnedHttpDestination::resolve('https://source.example:8443/token?q=1', $policy);
    }

    public function testFallsBackWithoutResolvingAgainAndKeepsTlsIdentityAndBudget(): void
    {
        $calls = [];
        $clock = 0;
        $result = fopen('php://memory', 'r+');
        $opener = new PinnedStreamOpener(static function ($url, $context) use (&$calls, &$clock, $result) {
            $calls[] = [$url, stream_context_get_options($context)];
            if (count($calls) === 1) {
                $clock += 15_000_000_000;
                return false;
            }
            return $result;
        }, static function () use (&$clock) {
            return $clock;
        });
        self::assertSame($result, $opener->open($this->destination(), ['method' => 'GET', 'follow_location' => 1, 'proxy' => 'tcp://proxy.invalid:80'], 30_000_000_000));
        self::assertSame('https://[2001:db8::1]:8443/token?q=1', $calls[0][0]);
        self::assertSame('https://192.0.2.1:8443/token?q=1', $calls[1][0]);
        foreach ($calls as [, $options]) {
            self::assertSame(15.0, $options['http']['timeout']);
            self::assertSame('', $options['http']['proxy']);
            self::assertSame(0, $options['http']['follow_location']);
            self::assertSame(0, $options['http']['max_redirects']);
            self::assertTrue($options['ssl']['verify_peer']);
            self::assertTrue($options['ssl']['verify_peer_name']);
            self::assertTrue($options['ssl']['SNI_enabled']);
            self::assertSame('source.example', $options['ssl']['peer_name']);
        }
        fclose($result);
    }

    public static function methods(): iterable
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            yield $method => [$method, false, 2];
        }
        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            yield $method => [$method, false, 1];
        }
        yield 'OAuth exchange' => ['POST', true, 2];
    }

    #[DataProvider('methods')]
    public function testBusinessWritesAreNotReplayed(string $method, bool $authExchange, int $expected): void
    {
        $calls = 0;
        $opener = new PinnedStreamOpener(static function () use (&$calls) {
            ++$calls;
            return false;
        }, static fn () => 0);
        self::assertFalse($opener->open($this->destination(), ['method' => $method], 10_000_000_000, $authExchange));
        self::assertSame($expected, $calls);
    }

    public function testExpiredDeadlineNeverOpensAnyAddress(): void
    {
        $opener = new PinnedStreamOpener(static function () {
            self::fail('Expired request opened a socket.');
        }, static fn () => 10_000_000_000);
        self::assertFalse($opener->open($this->destination(), [], 10_000_000_000));
    }

    public function testLateResourceIsClosedAndNoFurtherAttemptIsMade(): void
    {
        $clock = 0;
        $resource = fopen('php://memory', 'r+');
        $opener = new PinnedStreamOpener(static function () use (&$clock, $resource) {
            $clock = 11_000_000_000;
            return $resource;
        }, static function () use (&$clock) {
            return $clock;
        });
        self::assertFalse($opener->open($this->destination(), [], 10_000_000_000));
        self::assertFalse(is_resource($resource));
    }
}
