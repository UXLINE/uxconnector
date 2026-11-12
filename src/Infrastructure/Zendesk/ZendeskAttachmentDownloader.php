<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Zendesk;

use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * Downloads a Zendesk attachment without forwarding API credentials to a CDN.
 * HTTPS verification and the SSRF destination policy apply to EVERY redirect.
 * An incomplete temporary file is always removed. Error diagnostics never
 * contain signed URLs, attachment names or authentication tokens.
 */
final class ZendeskAttachmentDownloader
{
    private const MAX_BYTES = 50 * 1024 * 1024;
    private const MAX_REDIRECTS = 5;
    private const TIMEOUT_SECONDS = 60;

    private string $lastErrorCode = 'ATTACHMENT_HTTP_TRANSPORT';
    private string $lastErrorMessage = 'Unable to retrieve Zendesk attachment.';

    public function __construct(private readonly DestinationPolicyInterface $destinationPolicy)
    {
    }

    public function lastErrorCode(): string
    {
        return $this->lastErrorCode;
    }
    public function lastErrorMessage(): string
    {
        return $this->lastErrorMessage;
    }

    public function download(string $url, string $tempPath): bool
    {
        $this->error('ATTACHMENT_HTTP_TRANSPORT', 'Zendesk attachment transport failed.');
        $current = $url;
        try {
            for ($n = 0; $n <= self::MAX_REDIRECTS; $n++) {
                if (!$this->isAllowedUrl($current)) {
                    $this->error('ATTACHMENT_URL_BLOCKED', 'Attachment HTTPS destination failed security validation.');
                    return false;
                }

                $result = function_exists('curl_init')
                    ? $this->downloadCurlHop($current, $tempPath)
                    : $this->downloadStreamHop($current, $tempPath);

                if ($result['redirect'] !== null) {
                    // A redirect may change domains. Never forward Zendesk credentials.
                    $current = $this->resolveRedirectUrl($current, $result['redirect']);
                    continue;
                }
                if ($result['success']) {
                    return true;
                }
                return false;
            }
            $this->error('ATTACHMENT_TOO_MANY_REDIRECTS', 'Attachment exceeded the redirect safety limit.');
            return false;
        } finally {
            // The caller owns the file after a successful download. Every failed
            // path removes partial bytes, including DNS, timeout and size aborts.
            if ($this->lastErrorCode !== 'ATTACHMENT_OK' && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /** @return array{success:bool,redirect:?string} */
    private function downloadCurlHop(string $url, string $tempPath): array
    {
        $handle = @fopen($tempPath, 'wb');
        if ($handle === false) {
            $this->error('ATTACHMENT_TEMP_WRITE_FAILED', 'GLPI temporary directory cannot be written.');
            return ['success' => false, 'redirect' => null];
        }

        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            $this->error('ATTACHMENT_HTTP_TRANSPORT', 'Could not initialize HTTPS attachment download.');
            return ['success' => false, 'redirect' => null];
        }
        $length = 0;
        $tooLarge = false;
        $diskFailure = false;
        $location = null;
        $contentType = '';
        $advertisedBytes = null;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'UXConnector/2.18.25',
            CURLOPT_HEADERFUNCTION => static function (
                $ch,
                string $line
            ) use (
                &$location,
                &$contentType,
                &$advertisedBytes
): int {
                if (str_starts_with(strtolower($line), 'location:')) {
                    $location = trim(substr($line, 9));
                } elseif (str_starts_with(strtolower($line), 'content-type:')) {
                    $contentType = strtolower(trim(substr($line, 13)));
                } elseif (str_starts_with(strtolower($line), 'content-length:')) {
                    $value = trim(substr($line, 15));
                    $advertisedBytes = ctype_digit($value) ? (int) $value : null;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (
                $ch,
                string $chunk
            ) use (
                $handle,
                &$length,
                &$tooLarge,
                &$diskFailure
): int {
                $len = strlen($chunk);
                $length += $len;
                if ($length > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $written = fwrite($handle, $chunk);
                if ($written !== $len) {
                    $diskFailure = true;
                    return 0;
                }
                return $len;
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
        } else {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
        }

        try {
            $ok = curl_exec($curl);
            $error = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
            fclose($handle);
        }
        if ($tooLarge || ($advertisedBytes !== null && $advertisedBytes > self::MAX_BYTES)) {
            $this->error('ATTACHMENT_TOO_LARGE', 'Attachment exceeds the 50 MiB limit.');
            return ['success' => false, 'redirect' => null];
        }
        if ($diskFailure) {
            $this->error('ATTACHMENT_TEMP_WRITE_FAILED', 'Unable to write attachment to GLPI temporary directory.');
            return ['success' => false, 'redirect' => null];
        }
        if ($ok === false || $error !== 0) {
            if ($error === 60 || $error === 51) {
                $this->error('ATTACHMENT_TLS_FAILURE', 'TLS certificate validation failed during attachment download.');
            } elseif ($error === 28) {
                $this->error('ATTACHMENT_TIMEOUT', 'Zendesk attachment download timed out.');
            } else {
                $this->error('ATTACHMENT_CURL_ERROR', sprintf(
                    'Zendesk attachment HTTP transport error (cURL %d).',
                    $error
                ));
            }
            return ['success' => false, 'redirect' => null];
        }
        if ($status >= 300 && $status < 400) {
            if ($location === null || $location === '') {
                $this->error('ATTACHMENT_REDIRECT_MISSING', 'Attachment redirect omitted Location header.');
                return ['success' => false, 'redirect' => null];
            }
            return ['success' => false, 'redirect' => $location];
        }
        if ($status < 200 || $status >= 300) {
            $this->error('ATTACHMENT_HTTP_STATUS', sprintf('Zendesk attachment URL returned HTTP %d.', $status));
            return ['success' => false, 'redirect' => null];
        }
        if (
            str_contains($contentType, 'text/html') && !in_array(
                strtolower(pathinfo($tempPath, PATHINFO_EXTENSION)),
                ['html', 'htm'],
                true
            )
        ) {
            $this->error(
                'ATTACHMENT_HTML_RESPONSE',
                'Attachment endpoint returned HTML instead of a downloadable file.'
            );
            return ['success' => false, 'redirect' => null];
        }
        if ($length <= 0) {
            $this->error('ATTACHMENT_EMPTY_DOWNLOAD', 'Attachment download completed but returned no bytes.');
            return ['success' => false, 'redirect' => null];
        }
        $this->error('ATTACHMENT_OK', 'Attachment downloaded.');
        return ['success' => true, 'redirect' => null];
    }

    /** @return array{success:bool,redirect:?string} */
    private function downloadStreamHop(string $url, string $tempPath): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET', 'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                'header' => "User-Agent: UXConnector/2.18.25\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            $this->error(
                'ATTACHMENT_STREAM_FAILURE',
                'HTTPS attachment stream could not be opened (check TLS and network).'
            );
            return ['success' => false, 'redirect' => null];
        }
        $meta = stream_get_meta_data($stream);
        $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
        $status = 0;
        $location = null;
        $contentType = '';
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_starts_with(strtolower($header), 'location:')) {
                $location = trim(substr($header, 9));
            } elseif (str_starts_with(strtolower($header), 'content-type:')) {
                $contentType = strtolower(trim(substr($header, 13)));
            }
        }
        if ($status >= 300 && $status < 400) {
            fclose($stream);
            if ($location !== null && $location !== '') {
                return ['success' => false, 'redirect' => $location];
            }
            $this->error('ATTACHMENT_REDIRECT_MISSING', 'Attachment redirect omitted Location header.');
            return ['success' => false, 'redirect' => null];
        }
        if (
            $status < 200 || $status >= 300 || (str_contains($contentType, 'text/html') && !in_array(
                strtolower(pathinfo($tempPath, PATHINFO_EXTENSION)),
                ['html', 'htm'],
                true
            ))
        ) {
            fclose($stream);
            $this->error('ATTACHMENT_HTTP_STATUS', sprintf(
                'Attachment response was not downloadable (HTTP %d).',
                $status
            ));
            return ['success' => false, 'redirect' => null];
        }
        $dest = @fopen($tempPath, 'wb');
        if ($dest === false) {
            fclose($stream);
            $this->error('ATTACHMENT_TEMP_WRITE_FAILED', 'GLPI temporary directory cannot be written.');
            return ['success' => false, 'redirect' => null];
        }
        $bytes = 0;
        $good = true;
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || ($chunk === '' && !feof($stream))) {
                $good = false;
                break;
            }
            $bytes += strlen($chunk);
            if ($bytes > self::MAX_BYTES || fwrite($dest, $chunk) !== strlen($chunk)) {
                $good = false;
                break;
            }
        }
        fclose($dest);
        fclose($stream);
        if (!$good || $bytes <= 0) {
            $this->error(
                $bytes > self::MAX_BYTES ? 'ATTACHMENT_TOO_LARGE' : 'ATTACHMENT_STREAM_FAILURE',
                'Attachment stream was incomplete, empty or exceeded its size limit.',
            );
            return ['success' => false, 'redirect' => null];
        }
        $this->error('ATTACHMENT_OK', 'Attachment downloaded.');
        return ['success' => true, 'redirect' => null];
    }

    public function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['host'])) {
            return '';
        }
        $origin = 'https://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        if (str_starts_with($location, '?')) {
            return $origin . (string) ($parts['path'] ?? '/') . $location;
        }
        if (str_starts_with($location, '#')) {
            return $origin . (string) ($parts['path'] ?? '/')
                . (isset($parts['query']) ? '?' . $parts['query'] : '') . $location;
        }
        $directory = rtrim(str_replace('\\', '/', dirname((string) ($parts['path'] ?? '/'))), '/');
        return $origin . ($directory !== '' ? $directory : '') . '/' . $location;
    }

    private function isAllowedUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (
            !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            return false;
        }
        $host = (string) $parts['host'];
        $origin = 'https://' . (str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }
        try {
            $this->destinationPolicy->assertAllowed(new Endpoint($origin));
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    private function error(string $code, string $message): void
    {
        $this->lastErrorCode = $code;
        $this->lastErrorMessage = $message;
    }
}
