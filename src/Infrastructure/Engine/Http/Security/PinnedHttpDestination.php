<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Security;

use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/** Binds one HTTP attempt to approved addresses while retaining the TLS server identity. */
final readonly class PinnedHttpDestination
{
    /** @param non-empty-list<string> $addresses Approved addresses from the destination policy. */
    private function __construct(
        public string $url,
        public string $host,
        public int $port,
        private string $scheme,
        private array $addresses
    ) {
    }

    /** Resolves the original host once; callers must create a fresh instance for every redirect. */
    public static function resolve(string $url, DestinationPolicyInterface $policy): self
    {
        $parts = parse_url($url);
        if (
            !is_array($parts) || !isset($parts['host'], $parts['scheme'])
            || !in_array($parts['scheme'], ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $url)
        ) {
            throw new \RuntimeException('Invalid HTTP destination.');
        }
        $host = rtrim(trim(strtolower($parts['host']), '[]'), '.');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            // cURL accepts numeric IPv4 spellings that PHP may treat as DNS names.
            // Reject these aliases rather than let cURL bypass CURLOPT_RESOLVE.
            $numericPart = '(?:0x[0-9a-f]+|[0-9]+)';
            if (
                preg_match('/^' . $numericPart . '(?:\.' . $numericPart . '){0,3}$/D', $host)
                || !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host)
                || str_contains($host, '..')
            ) {
                throw new \RuntimeException('Ambiguous HTTP hostname is not permitted.');
            }
        }
        $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $addresses = $policy->resolveAllowed(new Endpoint($parts['scheme'] . '://' . $authority . ':' . $port));
        if ($addresses === []) {
            throw new \RuntimeException('No approved destination address.');
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new \RuntimeException('Destination policy returned an invalid address.');
            }
        }
        // A numeric URL must itself be approved: CURLOPT_RESOLVE does not replace numeric hosts.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && !in_array($host, $addresses, true)) {
            throw new \RuntimeException('Literal destination is not approved.');
        }
        $normalizedUrl = $parts['scheme'] . '://' . $authority . ':' . $port
            . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return new self($normalizedUrl, $host, $port, $parts['scheme'], $addresses);
    }

    /** @return array<int,mixed> Options applied after caller options to prevent overrides. */
    public function curlOptions(): array
    {
        $addresses = array_map(
            static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
            $this->addresses,
        );
        return [
            CURLOPT_RESOLVE => filter_var($this->host, FILTER_VALIDATE_IP) !== false
                ? [] : [$this->host . ':' . $this->port . ':' . implode(',', $addresses)],
            // An implicit HTTP proxy could resolve the hostname again outside this policy.
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
    }

    /** Returns a literal-IP URL, so the PHP wrapper cannot perform a second DNS lookup. */
    public function streamUrl(): string
    {
        $parts = parse_url($this->url);
        $ip = $this->addresses[0];
        $authority = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        return $this->scheme . '://' . $authority . ':' . $this->port
            . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** Original authority, independent of the numeric address used by the connection. */
    public function hostHeader(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        return 'Host: ' . $host . ':' . $this->port;
    }

    /** Attachments supplied by external users must not reach private or reserved networks. */
    public function requirePublicAddresses(): void
    {
        foreach ($this->addresses as $address) {
            $packed = inet_pton($address);
            if (strlen($packed) === 16 && substr($packed, 0, 12) === hex2bin('00000000000000000000ffff')) {
                $address = inet_ntop(substr($packed, 12));
            }
            if (
                filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
                === false
            ) {
                throw new \RuntimeException('Attachment destination is not a public address.');
            }
        }
    }

    /** @return array<string,mixed> Certificate and SNI checks still use the original hostname. */
    public function tlsOptions(): array
    {
        return ['verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => $this->host, 'SNI_enabled' => true];
    }
}
