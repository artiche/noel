<?php

declare(strict_types=1);

namespace Noel\Api\Infrastructure;

final class CurlMetadataNetwork implements MetadataNetwork
{
    public function __construct(private readonly string $phpCli)
    {
    }

    public function isAvailable(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('proc_open') || !function_exists('proc_terminate')
            || !function_exists('proc_get_status') || !function_exists('proc_close')
            || !function_exists('curl_init') || !function_exists('curl_version') || !defined('CURLOPT_RESOLVE')
            || !defined('CURLINFO_PRIMARY_IP') || !defined('CURL_VERSION_SSL') || !function_exists('hrtime')
            || (!defined('CURLOPT_PROTOCOLS_STR') && (!defined('CURLOPT_PROTOCOLS') || !defined('CURLPROTO_HTTPS')))) {
            return false;
        }
        $version = curl_version();
        return is_array($version) && ($version['version_number'] ?? 0) >= 0x073b00
            && (($version['features'] ?? 0) & CURL_VERSION_SSL) !== 0
            && in_array('http', $version['protocols'] ?? [], true)
            && in_array('https', $version['protocols'] ?? [], true);
    }

    public function resolve(string $host, float $deadline): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            return null;
        }
        $process = @proc_open([$this->phpCli, '-d', 'display_errors=0', '-d', 'log_errors=0',
            dirname(__DIR__) . '/resolve-metadata-host.php', $host], [
                0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w'],
            ], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $output = '';
        $status = ['running' => true, 'exitcode' => -1];
        do {
            $chunk = stream_get_contents($pipes[1], 16385 - strlen($output));
            if ($chunk === false) {
                break;
            }
            $output .= $chunk;
            $status = proc_get_status($process);
            if (!$status['running'] || strlen($output) > 16384 || hrtime(true) / 1e9 >= $deadline) {
                break;
            }
            usleep(10000);
        } while (true);
        if (!$status['running'] && strlen($output) <= 16384) {
            $remainingOutput = stream_get_contents($pipes[1], 16385 - strlen($output));
            if ($remainingOutput !== false) $output .= $remainingOutput;
        }
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        proc_close($process);
        if ($status['running'] || $status['exitcode'] !== 0 || strlen($output) > 16384) {
            return null;
        }
        $addresses = json_decode($output, true);
        if (!is_array($addresses) || !array_is_list($addresses) || count($addresses) > 64
            || array_filter($addresses, static fn (mixed $address): bool => !is_string($address)) !== []) {
            return null;
        }
        return $addresses;
    }

    public function request(string $url, string $host, int $port, string $address, float $deadline): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $remaining = (int) floor(($deadline - hrtime(true) / 1e9) * 1000);
        if ($remaining <= 0) {
            return null;
        }
        $curl = curl_init();
        if ($curl === false) {
            return null;
        }
        $body = '';
        $headerBytes = 0;
        $location = null;
        $contentType = '';
        $contentEncoding = '';
        $invalidHeaders = false;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, $remaining),
            CURLOPT_TIMEOUT_MS => $remaining,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => ['Accept: text/html, application/xhtml+xml', 'Accept-Encoding: identity'],
            CURLOPT_USERAGENT => 'Noel-Metadata/1.0',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headerBytes, &$location, &$contentType, &$contentEncoding, &$invalidHeaders): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 32768) {
                    return 0;
                }
                if (preg_match('/^HTTP\/\S+ \d{3}/i', $line) === 1) {
                    $location = null;
                    $contentType = '';
                    $contentEncoding = '';
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $value = trim($value);
                    switch (strtolower($name)) {
                        case 'location':
                            if ($location !== null) $invalidHeaders = true;
                            $location = $value;
                            break;
                        case 'content-type':
                            if ($contentType !== '') $invalidHeaders = true;
                            $contentType = $value;
                            break;
                        case 'content-encoding':
                            if ($contentEncoding !== '') $invalidHeaders = true;
                            $contentEncoding = $value;
                            break;
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $pinned];
        }
        if (!curl_setopt_array($curl, $options)) {
            curl_close($curl);
            return null;
        }
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $connected = curl_getinfo($curl, CURLINFO_PRIMARY_IP);
        curl_close($curl);
        $expectedPacked = @inet_pton($address);
        $connectedPacked = is_string($connected) ? @inet_pton($connected) : false;
        if ($ok === false || $invalidHeaders || $expectedPacked === false || $connectedPacked === false
            || !hash_equals($expectedPacked, $connectedPacked)) {
            return null;
        }
        return ['status' => (int) $status, 'location' => $location, 'contentType' => $contentType,
            'contentEncoding' => $contentEncoding, 'body' => $body];
    }
}
