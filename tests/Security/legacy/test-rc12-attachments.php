<?php

declare(strict_types=1);
// Simulated HTTPS stream only in the test namespace: production downloader and policy are unchanged.

namespace GlpiPlugin\Uxconnector\Infrastructure\Zendesk {
    function function_exists(string $name): bool
    {
        return $name === 'curl_init' ? false : \function_exists($name);
    }
    function fopen(string $path, string $mode, bool $include = false, $context = null)
    {
        if (!str_starts_with($path, 'https://')) {
            return \fopen($path, $mode);
        }
        $GLOBALS['qa_opened'][] = ['url' => $path,'context' => stream_context_get_options($context)];
        $response = array_shift($GLOBALS['qa_responses']);
        if ($response === null) {
            throw new \LogicException('Unexpected request');
        }
        $stream = \fopen('php://temp', 'w+b');
        fwrite($stream, $response['body']);
        rewind($stream);
        $GLOBALS['qa_headers'][get_resource_id($stream)] = $response['headers'];
        return $stream;
    }
    function stream_get_meta_data($stream): array
    {
        return array_replace(\stream_get_meta_data($stream), ['wrapper_data' => $GLOBALS['qa_headers'][get_resource_id($stream)] ?? []]);
    }
}

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security {
    // The opener now owns fopen. Keep the same response fixture and downloader assertions.
    function fopen(string $path, string $mode, bool $include = false, $context = null)
    {
        return \GlpiPlugin\Uxconnector\Infrastructure\Zendesk\fopen($path, $mode, $include, $context);
    }
}

namespace {
    define('PLUGIN_UXCONNECTOR_VERSION', '2.18.39');
    spl_autoload_register(static function (string $class) use ($argv): void {
        $prefix = 'GlpiPlugin\\Uxconnector\\';
        if (str_starts_with($class, $prefix)) {
            require $argv[1].'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    });
    use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
    use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
    use GlpiPlugin\Uxconnector\Infrastructure\Zendesk\ZendeskAttachmentDownloader;

    function check(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new \LogicException($message);
        }
    }
    $policy = new class () implements DestinationPolicyInterface {
        public array $addresses = [];
        public function assertAllowed(Endpoint $e): void
        {
        }
        public function resolveAllowed(Endpoint $e): array
        {
            return [array_shift($this->addresses) ?? '93.184.216.34'];
        }
    };
    $file = tempnam(sys_get_temp_dir(), 'uxc-qa-');
    $redirect = static fn (string $url) => ['body' => '','headers' => ['HTTP/1.1 302 Found','Location: '.$url]];
    $ok = ['body' => 'fictitious attachment','headers' => ['HTTP/1.1 200 OK','Content-Type: image/png']];
    try {
        foreach (['valid','cdn','evil','downgrade','rebind'] as $mode) {
            $GLOBALS['qa_opened'] = [];
            $GLOBALS['qa_headers'] = [];
            $policy->addresses = $mode === 'rebind' ? ['93.184.216.34','10.0.0.1'] : ['93.184.216.34','93.184.216.35'];
            $next = match($mode) {
                'cdn' => 'https://p17.zdusercontent.com/a','evil' => 'https://attacker.example/a',
                'downgrade' => 'http://tenant.zendesk.com/a',default => 'https://tenant.zendesk.com/next'
            };
            $GLOBALS['qa_responses'] = $mode === 'valid' ? [$ok] : [$redirect($next),$ok];
            $downloader = new ZendeskAttachmentDownloader($policy, new Endpoint('https://tenant.zendesk.com'));
            $success = $downloader->download('https://tenant.zendesk.com/attachment', $file);
            check($success === in_array($mode, ['valid','cdn'], true), 'Attachment decision mismatch: '.$mode);
            check(count($GLOBALS['qa_opened']) === ($mode === 'cdn' ? 2 : 1), 'Unexpected second network request: '.$mode);
            foreach ($GLOBALS['qa_opened'] as $request) {
                check(str_starts_with($request['url'], 'https://93.184.216.'), 'Stream did not use pinned IP');
                check(!str_contains(strtolower($request['context']['http']['header']), 'authorization:'), 'Credential forwarded to CDN');
            }
            if ($success) {
                check(file_get_contents($file) === $ok['body'], 'Attachment changed');
                unlink($file);
            } else {
                check(!file_exists($file), 'Failed download left a temporary file');
            }
            echo "OK RC12 attachment stream simulation $mode: host policy, public IP restriction, cleanup\n";
        }
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
