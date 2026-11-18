<?php

declare(strict_types=1);

namespace Noel\Api\Infrastructure;

use DOMDocument;
use ValueError;

final class OpenGraphFetcher
{
    public function __construct(private readonly MetadataNetwork $network)
    {
    }

    /** @return array{status:string,title:?string,source:?string} */
    public function fetch(string $url): array
    {
        if (!$this->network->isAvailable() || !class_exists(DOMDocument::class) || !function_exists('mb_convert_encoding')
            || !function_exists('mb_encode_numericentity') || !function_exists('mb_strcut') || !function_exists('mb_check_encoding')
            || !function_exists('hrtime') || !defined('FILTER_FLAG_GLOBAL_RANGE')) {
            return $this->unavailable('required capabilities unavailable');
        }
        $deadline = hrtime(true) / 1e9 + 5.0;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $target = $this->target($url);
            if ($target === null || hrtime(true) / 1e9 >= $deadline) {
                return $this->unavailable('invalid target or timeout');
            }
            $host = $target['host'];
            $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
                ? [$host] : $this->network->resolve($host, $deadline);
            if ($addresses === null || $addresses === [] || count($addresses) > 64) {
                return $this->unavailable('DNS resolution failed');
            }
            foreach ($addresses as $address) {
                if (!$this->isPublicAddress($address)) {
                    return $this->unavailable('non-public address');
                }
            }
            $response = $this->network->request($target['url'], $host, $target['port'], $addresses[0], $deadline);
            if ($response === null || hrtime(true) / 1e9 >= $deadline || strlen($response['body']) > 262144) {
                return $this->unavailable('network request failed or exceeded its limit');
            }
            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                if ($redirects === 3 || $response['location'] === null) {
                    return $this->unavailable('invalid or excessive redirects');
                }
                $next = $this->redirectUrl($target['url'], $response['location']);
                if ($next === null) {
                    return $this->unavailable('invalid redirect');
                }
                $url = $next;
                continue;
            }
            if ($response['status'] !== 200 || !in_array(strtolower(trim(explode(';', $response['contentType'], 2)[0])),
                ['text/html', 'application/xhtml+xml'], true)
                || !in_array(strtolower(trim($response['contentEncoding'])), ['', 'identity'], true)) {
                return $this->unavailable('unsupported response');
            }
            return $this->extractTitle($response['body'], $response['contentType']);
        }
        return $this->unavailable('redirect limit');
    }

    /** @return array{url:string,host:string,port:int}|null */
    private function target(string $url): ?array
    {
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port !== ($scheme === 'https' ? 443 : 80)) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            if (strlen($host) > 253 || preg_match('/^(?:(?:0x[0-9a-f]+|[0-9]+)\.?)+$/iD', $host) === 1
                || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/D', $host) !== 1) {
                return null;
            }
        }
        $fragment = strpos($url, '#');
        return ['url' => $fragment === false ? $url : substr($url, 0, $fragment), 'host' => $host, 'port' => $port];
    }

    private function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
                '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
                '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
                '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $range) {
                if ($this->inRange($packed, $range)) return false;
            }
            return true;
        }
        if (strlen($packed) !== 16 || !$this->inRange($packed, '2000::/3')) return false;
        foreach (['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'] as $range) {
            if ($this->inRange($packed, $range)) return false;
        }
        return true;
    }

    private function inRange(string $address, string $range): bool
    {
        [$network, $bits] = explode('/', $range, 2);
        $packed = inet_pton($network);
        $whole = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        return substr($address, 0, $whole) === substr($packed, 0, $whole)
            && ($rest === 0 || (ord($address[$whole]) & (0xff << (8 - $rest))) === (ord($packed[$whole]) & (0xff << (8 - $rest))));
    }

    private function redirectUrl(string $base, string $location): ?string
    {
        if ($location === '' || strlen($location) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $location) === 1) {
            return null;
        }
        $original = parse_url($base);
        if (!is_array($original) || !isset($original['scheme'], $original['host'])) return null;
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location) === 1) return $location;
        if (str_starts_with($location, '//')) return $original['scheme'] . ':' . $location;
        $relative = parse_url($location);
        if (!is_array($relative) || isset($relative['host']) || isset($relative['user'])) return null;
        $path = $relative['path'] ?? '';
        if ($path === '') {
            $path = $original['path'] ?? '/';
            $query = $relative['query'] ?? ($original['query'] ?? null);
        } else {
            if (!str_starts_with($path, '/')) {
                $basePath = $original['path'] ?? '/';
                $path = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $path;
            }
            $segments = [];
            foreach (explode('/', $path) as $segment) {
                if ($segment === '..') array_pop($segments);
                elseif ($segment !== '.' && $segment !== '') $segments[] = $segment;
            }
            $path = '/' . implode('/', $segments) . (str_ends_with($path, '/') && $segments !== [] ? '/' : '');
            $query = $relative['query'] ?? null;
        }
        return $original['scheme'] . '://' . $original['host']
            . (isset($original['port']) ? ':' . $original['port'] : '') . $path
            . ($query === null ? '' : '?' . $query);
    }

    /** @return array{status:string,title:?string,source:?string} */
    private function extractTitle(string $body, string $contentType): array
    {
        if (!class_exists(DOMDocument::class) || !function_exists('mb_convert_encoding')) {
            return $this->unavailable('HTML parser unavailable');
        }
        $charset = null;
        if (preg_match('/charset\s*=\s*["\']?([a-z0-9._-]+)/i', $contentType, $match) === 1
            || preg_match('/<meta\s+[^>]*charset\s*=\s*["\']?([a-z0-9._-]+)/i', substr($body, 0, 4096), $match) === 1) {
            $charset = $match[1];
        }
        $charset ??= mb_check_encoding($body, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        try {
            $html = mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (ValueError) {
            return $this->unavailable('unsupported character encoding');
        }
        $html = mb_encode_numericentity($html, [0x80, 0x10ffff, 0, 0x1fffff], 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                return $this->unavailable('invalid HTML');
            }
            foreach ($document->getElementsByTagName('meta') as $meta) {
                if (strtolower(trim($meta->getAttribute('property'))) === 'og:title'
                    || strtolower(trim($meta->getAttribute('name'))) === 'og:title') {
                    $title = $this->cleanTitle($meta->getAttribute('content'));
                    if ($title !== '') return ['status' => 'found', 'title' => $title, 'source' => 'opengraph'];
                }
            }
            foreach ($document->getElementsByTagName('title') as $element) {
                $title = $this->cleanTitle($element->textContent);
                if ($title !== '') return ['status' => 'found', 'title' => $title, 'source' => 'html'];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return ['status' => 'none', 'title' => null, 'source' => null];
    }

    private function cleanTitle(string $title): string
    {
        $title = preg_replace('/[\p{C}\p{Z}\s]+/u', ' ', $title);
        return $title === null ? '' : trim(mb_strcut($title, 0, 4096, 'UTF-8'));
    }

    /** @return array{status:string,title:?string,source:?string} */
    private function unavailable(string $reason): array
    {
        error_log('[metadata] unavailable: ' . $reason);
        return ['status' => 'unavailable', 'title' => null, 'source' => null];
    }
}
