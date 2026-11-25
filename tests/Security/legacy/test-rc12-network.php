<?php

declare(strict_types=1);
spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'GlpiPlugin\\Uxconnector\\';
    if (str_starts_with($class, $prefix)) {
        require $argv[1].'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security\{DestinationPolicy,HostResolverInterface,PinnedHttpDestination};
use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\{ZendeskAttachmentDownloader, ZendeskAttachmentHostPolicy};

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new LogicException($message);
    }
}
function rejects(callable $f): void
{
    try {
        $f();
    } catch (RuntimeException|InvalidArgumentException $e) {
        return;
    } throw new LogicException('Expected rejection');
}
putenv('UXCONNECTOR_ALLOWED_HOSTS');
$resolver = new class () implements HostResolverInterface {
    public array $ips = ['93.184.216.34'];
    public int $calls = 0;
    public function resolve(string $host): array
    {
        $this->calls++;
        return $this->ips;
    }
};
$policy = new DestinationPolicy($resolver);
foreach (['127.0.0.1','169.254.169.254','0.0.0.0','100.64.0.1','224.0.0.1','255.255.255.255',
          '::1','::','fe80::1','fc00::1','fd00:ec2::254','64:ff9b::a9fe:a9fe','64:ff9b:1::1',
          '::ffff:7f00:1','::ffff:a9fe:a9fe','::127.0.0.1','2002:7f00:1::1','2001::1','ff02::1'] as $ip) {
    $resolver->ips = [$ip];
    rejects(fn () => $policy->resolveAllowed(new Endpoint('https://fixture.invalid')));
}
foreach (['10.2.3.4','172.19.82.12','192.168.1.5','93.184.216.34','2606:4700:4700::1111'] as $ip) {
    $resolver->ips = [$ip];
    check($policy->resolveAllowed(new Endpoint('https://fixture.invalid')) === [$ip], 'Legitimate network rejected');
}
$resolver->ips = ['93.184.216.34','127.0.0.1'];
rejects(fn () => $policy->resolveAllowed(new Endpoint('https://fixture.invalid')));
$resolver->ips = ['93.184.216.34'];
$resolver->calls = 0;
$pin = PinnedHttpDestination::resolve('https://fixture.invalid/path?a=1', $policy);
$resolver->ips = ['127.0.0.1'];
check($pin->streamUrls() === ['https://93.184.216.34:443/path?a=1'], 'Pinned stream address changed');
check($pin->hostHeader() === 'Host: fixture.invalid:443', 'Host lost');
check($pin->tlsOptions()['peer_name'] === 'fixture.invalid', 'TLS identity lost');
check($resolver->calls === 1, 'Second resolution');
foreach (['10.1.1.1','::ffff:a01:101','fc00::1','127.0.0.1','198.18.0.1','192.0.2.1','192.0.0.1'] as $ip) {
    $fake = new class ($ip) implements DestinationPolicyInterface {
        public function __construct(private string $ip)
        {
        }public function assertAllowed(Endpoint $e): void
        {
        }
        public function resolveAllowed(Endpoint $e): array
        {
            return [$this->ip];
        }
    };
    rejects(fn () => PinnedHttpDestination::resolve('https://fixture.invalid', $fake)->requirePublicAddresses());
}
foreach (['https://2130706433','https://0177.0.0.1','https://0x7f000001','https://127.1',
          'https://host%2Eexample.com','https://bad..example.com'] as $ambiguous) {
    rejects(fn () => PinnedHttpDestination::resolve($ambiguous, $policy));
}
$tenant = new Endpoint('https://tenant.zendesk.com');
foreach (['https://tenant.zendesk.com/attachment','https://p17.zdusercontent.com/a?token=fake'] as $url) {
    check(ZendeskAttachmentHostPolicy::allows($url, $tenant), 'Valid attachment host rejected');
}
foreach (['https://attacker.example/a','https://other.zendesk.com/a','https://tenant.zendesk.com.attacker.example/a',
          'https://zdusercontent.com.attacker.example/a','https://evilzdusercontent.com/a','https://p17.zdusercontent.com:444/a',
          'http://tenant.zendesk.com/a','https://user:secret@tenant.zendesk.com/a','https://127.0.0.1/a'] as $url) {
    check(!ZendeskAttachmentHostPolicy::allows($url, $tenant), 'Unsafe attachment host allowed');
}
$downloader = new ZendeskAttachmentDownloader($policy, $tenant);
$resolver->calls = 0;
$file = sys_get_temp_dir().'/uxc-blocked-'.bin2hex(random_bytes(5));
check(!$downloader->download('https://attacker.example/a', $file) && $resolver->calls === 0 && !file_exists($file), 'Untrusted host attempted');
check(!$downloader->download('https://tenant.zendesk.com/a', $file) && !file_exists($file), 'Private address attempted');
echo "OK RC12: 20 blocked IP forms, legitimate internal IPv4 retained, mixed DNS denied, pinned address immutable, attachment host boundaries/private destinations rejected\n";
