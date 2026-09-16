<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\Engine\Http\Security;

use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security\DestinationPolicy;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security\HostResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DestinationPolicy::class)]
final class DestinationPolicyTest extends TestCase
{
   private function makePolicy(array $dnsMap = []): DestinationPolicy {
       $resolver = new class($dnsMap) implements HostResolverInterface {
         public function __construct(private readonly array $map) {
         }

         public function resolve(string $hostname): array {
             return $this->map[$hostname] ?? [];
         }
       };

         return new DestinationPolicy($resolver);
   }

    /**
     * @return iterable<string, array{0: string}>
     */
   public static function blockedLiteralEndpoints(): iterable {
       yield 'IPv4 loopback' => ['http://127.0.0.1/api'];
       yield 'IPv4 loopback (127.x range)' => ['http://127.5.5.5/api'];
       yield 'cloud instance metadata (AWS/Azure/GCP)' => ['http://169.254.169.254/latest/meta-data/'];
       yield 'IPv4 link-local' => ['http://169.254.1.1/api'];
       yield '"this network"' => ['http://0.0.0.0/api'];
       yield 'IPv6 loopback' => ['http://[::1]/api'];
       yield 'IPv6 link-local' => ['http://[fe80::1]/api'];
       yield 'IPv4-mapped IPv6 loopback' => ['http://[::ffff:127.0.0.1]/api'];
       yield 'IPv4-mapped IPv6 loopback hexadecimal spelling' => ['http://[::ffff:7f00:1]/api'];
       yield 'IPv4-mapped IPv6 metadata hexadecimal spelling' => ['http://[::ffff:a9fe:a9fe]/api'];
   }

    #[DataProvider('blockedLiteralEndpoints')]
   public function testBlockedLiteralAddressesAreRejected(string $endpointValue): void {
       $policy = $this->makePolicy();

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::class);

       $policy->assertAllowed(new Endpoint($endpointValue));
   }

    /**
     * @return iterable<string, array{0: string}>
     */
   public static function legitimateLiteralEndpoints(): iterable {
       // RFC1918 private ranges: this plugin's core use case is
       // reaching internal systems (Zabbix, MDM) living exactly here
       // â€” they must never be blocked by default.
       yield 'RFC1918 10.x' => ['http://10.40.5.20/zabbix'];
       yield 'RFC1918 172.16.x' => ['http://172.16.0.5/api'];
       yield 'RFC1918 192.168.x' => ['http://192.168.1.50/api'];
       yield 'public IP' => ['https://8.8.8.8/api'];
   }

    #[DataProvider('legitimateLiteralEndpoints')]
   public function testLegitimateLiteralAddressesAreAllowed(string $endpointValue): void {
       $policy = $this->makePolicy();

       $policy->assertAllowed(new Endpoint($endpointValue));

       $this->addToAssertionCount(1); // no exception thrown = pass
   }

   public function testHostnameResolvingToAPublicAddressIsAllowed(): void {
       $policy = $this->makePolicy(['graph.microsoft.com' => ['20.190.151.68']]);

       $policy->assertAllowed(new Endpoint('https://graph.microsoft.com/v1.0'));

       $this->addToAssertionCount(1);
   }

   public function testHostnameResolvingToAnInternalPrivateAddressIsAllowed(): void {
       $policy = $this->makePolicy(['zabbix.example.local' => ['10.40.5.20']]);

       $policy->assertAllowed(new Endpoint('https://zabbix.example.local/api'));

       $this->addToAssertionCount(1);
   }

    /**
     * The classic DNS-based SSRF bypass: a hostname that looks
     * innocuous but resolves to a forbidden address.
     */
   public function testHostnameResolvingToLoopbackIsRejected(): void {
       $policy = $this->makePolicy(['attacker.example.com' => ['127.0.0.1']]);

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::class);

       $policy->assertAllowed(new Endpoint('https://attacker.example.com/api'));
   }

   public function testUnresolvableHostnameIsRejected(): void {
       $policy = $this->makePolicy(['unresolvable.invalid' => []]);

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::class);

       $policy->assertAllowed(new Endpoint('https://unresolvable.invalid/api'));
   }

    /**
     * A hostname resolving to several addresses is rejected as soon
     * as any one of them is forbidden â€” an attacker controlling DNS
     * cannot hide a forbidden address among legitimate ones.
     */
   public function testHostnameResolvingToMultipleAddressesIsRejectedIfAnyIsForbidden(): void {
       $policy = $this->makePolicy(['multi.example.com' => ['8.8.8.8', '127.0.0.1']]);

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException::class);

       $policy->assertAllowed(new Endpoint('https://multi.example.com/api'));
   }
}

