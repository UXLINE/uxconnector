<?php

declare(strict_types=1);
spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'GlpiPlugin\\Uxconnector\\';
    if (str_starts_with($class, $prefix)) {
        require $argv[1].'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});
use GlpiPlugin\Uxconnector\Domain\Engine\Http\{HttpMethod, HttpRequest};
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication\{GlpiRestV1AuthenticationStrategy, OAuth2TokenClient};
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\GenericHttpEngine;

function check(bool $ok, string $m): void
{
    if (!$ok) {
        throw new LogicException($m);
    }
}
$base = $argv[2];
$mode = $argv[3];
$policy = new class () implements DestinationPolicyInterface {
    public int $calls = 0;
    public function assertAllowed(Endpoint $e): void
    {
    }
    public function resolveAllowed(Endpoint $e): array
    {
        $this->calls++;
        return ['::1', '127.0.0.1'];
    }
};
$request = new HttpRequest(HttpMethod::GET, '/valid', ['Host' => 'attacker.invalid']);
$engine = (new ReflectionClass(GenericHttpEngine::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($engine, 'destinationPolicy'))->setValue($engine, $policy);
$method = new ReflectionMethod($engine, $mode === 'curl' ? 'sendWithCurl' : 'sendWithStreams');
foreach (['valid','redirect','large','wrongname'] as $scenario) {
    $url = $scenario === 'wrongname' ? str_replace('fixture.invalid', 'wrong.invalid', $base).'/valid' : $base.'/'.$scenario;
    $before = $policy->calls;
    $error = null;
    try {
        $response = $method->invoke($engine, $url, $request, true);
    } catch (Throwable $e) {
        $error = $e;
    }
    check($policy->calls === $before + 1, 'One resolution required per attempt');
    if ($scenario === 'wrongname' || $scenario === 'large') {
        check($error !== null, 'TLS/size rejection missing');
        continue;
    }
    if ($error) {
        throw $error;
    }
    check($response->statusCode === ($scenario === 'redirect' ? 302 : 200), 'HTTP status changed');
    if ($scenario === 'valid') {
        $data = json_decode($response->body, true);
        check($data['host'] === substr($base, 8), 'Original host lost or caller host injected');
        check($data['sni'] === 'fixture.invalid', 'TLS SNI did not use original host');
    }
}
$public = new HttpRequest(HttpMethod::GET, '/valid', requirePublicDestination:true);
check($public->withHeader('Authorization', 'FAKE')->requirePublicDestination, 'Authentication dropped public-only restriction');
$blocked = false;
try {
    $method->invoke($engine, $base.'/valid', $public, true);
} catch (RuntimeException) {
    $blocked = true;
}
check($blocked, 'Authenticated attachment fallback reached private address');
$oauth = new OAuth2TokenClient($policy);
check($oauth->fetchClientCredentialsToken($base.'/token', 'fake-client', 'FAKE-SECRET', null) === 'qa-token', 'OAuth pin');
$rest = new GlpiRestV1AuthenticationStrategy(new Endpoint($base), json_encode(['app_token' => 'FAKE-APP','user_token' => 'FAKE-USER']), $policy);
check($rest->authenticate($request)->headers['Session-Token'] === 'qa-session', 'REST v1 pin');
$rest->closeSession();
echo "OK dual-stack HTTPS $mode: pinned nonexistent DNS host, correct Host and SNI, invalid certificate name rejected, no redirect, response size limit, OAuth2 and REST v1 init/kill\n";
