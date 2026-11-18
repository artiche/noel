<?php

declare(strict_types=1);

use Noel\Api\Infrastructure\MetadataNetwork;
use Noel\Api\Infrastructure\OpenGraphFetcher;
use Noel\Api\Infrastructure\CurlMetadataNetwork;

final class FakeMetadataNetwork implements MetadataNetwork
{
    /** @var array<string,list<string>|null> */
    public array $dns = ['example.test' => ['8.8.8.8']];
    /** @var list<array{url:string,host:string,port:int,address:string}> */
    public array $requests = [];
    /** @var list<array{status:int,location:?string,contentType:string,contentEncoding:string,body:string}|null> */
    public array $responses = [];
    public $onRequest = null;
    public bool $available = true;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function resolve(string $host, float $deadline): ?array
    {
        return $this->dns[$host] ?? null;
    }

    public function request(string $url, string $host, int $port, string $address, float $deadline): ?array
    {
        $this->requests[] = compact('url', 'host', 'port', 'address');
        if ($this->onRequest !== null) ($this->onRequest)();
        return array_shift($this->responses);
    }
}

function metadataResponse(string $body, int $status = 200, ?string $location = null,
    string $contentType = 'text/html; charset=UTF-8', string $contentEncoding = ''): array
{
    return compact('body', 'status', 'location', 'contentType', 'contentEncoding');
}

$metadataNetwork = new FakeMetadataNetwork();
$metadataFetcher = new OpenGraphFetcher($metadataNetwork);
$metadataNetwork->available = false;
assertEquals('unavailable', $metadataFetcher->fetch('https://example.test/')['status'], 'missing capabilities disable metadata');
assertEquals([], $metadataNetwork->requests, 'disabled metadata does not contact the network');
$metadataNetwork->available = true;
$metadataNetwork->responses[] = metadataResponse('<html><head><title>Fallback</title>'
    . '<meta property="og:title" content="Café &amp; thé"></head></html>');
$result = $metadataFetcher->fetch('https://example.test/page#secret');
assertEquals(['status' => 'found', 'title' => 'Café & thé', 'source' => 'opengraph'], $result, 'OpenGraph takes precedence and decodes entities');
assertEquals('https://example.test/page', $metadataNetwork->requests[0]['url'], 'fragment is never sent');
assertEquals('8.8.8.8', $metadataNetwork->requests[0]['address'], 'validated DNS address is pinned');

$metadataNetwork->responses[] = metadataResponse('<title>  Livre &lt;rare&gt;  </title>');
assertEquals(['status' => 'found', 'title' => 'Livre <rare>', 'source' => 'html'],
    $metadataFetcher->fetch('http://example.test/'), 'HTML title is the fallback');
$metadataNetwork->responses[] = metadataResponse("<meta charset=\"windows-1252\"><title>Caf\xe9</title>", 200, null, 'text/html');
assertEquals('Café', $metadataFetcher->fetch('https://example.test/')['title'], 'declared page encoding is respected');
$metadataNetwork->responses[] = metadataResponse('<meta property="og:title" content="' . str_repeat('é', 3000) . '">');
$longTitle = $metadataFetcher->fetch('https://example.test/')['title'];
assertEquals(4096, strlen($longTitle), 'title is limited by UTF-8 bytes');
assertTrue(mb_check_encoding($longTitle, 'UTF-8'), 'truncated title remains valid UTF-8');
$metadataNetwork->responses[] = metadataResponse('<html><head></head><body>No title</body></html>');
assertEquals('none', $metadataFetcher->fetch('https://example.test/')['status'], 'missing title is explicit');

foreach ([
    'http://127.0.0.1/', 'http://10.1.2.3/', 'http://100.64.0.1/', 'http://169.254.169.254/',
    'http://192.0.2.1/', 'http://198.18.0.1/', 'http://224.0.0.1/', 'http://[::1]/',
    'http://[::ffff:8.8.8.8]/', 'http://[64:ff9b::808:808]/', 'http://[2001:db8::1]/',
    'http://[2002:0808:0808::1]/', 'http://0177.0.0.1/', 'http://2130706433/',
    'http://0x7f000001/', 'https://user:password@example.test/', 'http://example.test:8080/',
    'https://example.test:80/', 'file:///etc/passwd', 'http://example.test\\@127.0.0.1/',
] as $unsafe) {
    $before = count($metadataNetwork->requests);
    assertEquals('unavailable', $metadataFetcher->fetch($unsafe)['status'], 'unsafe URL must be refused');
    assertEquals($before, count($metadataNetwork->requests), 'unsafe URL must never reach HTTP transport');
}
$metadataNetwork->dns['mixed.test'] = ['8.8.8.8', '127.0.0.1'];
$before = count($metadataNetwork->requests);
assertEquals('unavailable', $metadataFetcher->fetch('https://mixed.test/')['status'], 'mixed public and private DNS must fail');
assertEquals($before, count($metadataNetwork->requests), 'mixed DNS does not connect even to the public address');

$metadataNetwork->responses[] = metadataResponse('', 302, 'http://169.254.169.254/latest');
$before = count($metadataNetwork->requests);
assertEquals('unavailable', $metadataFetcher->fetch('https://example.test/')['status'], 'redirect to metadata service is refused');
assertEquals($before + 1, count($metadataNetwork->requests), 'only the first public host is contacted');
$metadataNetwork->dns['other.test'] = ['2606:4700:4700::1111'];
$metadataNetwork->responses[] = metadataResponse('', 302, 'https://other.test/product');
$metadataNetwork->responses[] = metadataResponse('<title>Redirected</title>');
assertEquals('Redirected', $metadataFetcher->fetch('https://example.test/')['title'], 'safe redirect is resolved and fetched');
assertEquals('2606:4700:4700::1111', end($metadataNetwork->requests)['address'], 'redirect address is independently pinned');
$metadataNetwork->responses[] = metadataResponse('', 302, '../produit?x=1');
$metadataNetwork->responses[] = metadataResponse('<title>Relative</title>');
assertEquals('Relative', $metadataFetcher->fetch('https://example.test/a/page')['title'], 'relative redirects are supported');
assertEquals('https://example.test/produit?x=1', end($metadataNetwork->requests)['url'], 'relative redirect is normalized');
for ($i = 0; $i < 4; $i++) $metadataNetwork->responses[] = metadataResponse('', 302, '/loop');
assertEquals('unavailable', $metadataFetcher->fetch('https://example.test/')['status'], 'redirect count is bounded');
$metadataNetwork->responses[] = metadataResponse(str_repeat('x', 262145));
assertEquals('unavailable', $metadataFetcher->fetch('https://example.test/')['status'], 'response body is bounded');
$metadataNetwork->responses[] = metadataResponse('<title>Unsafe</title>', 200, null, 'application/json');
assertEquals('unavailable', $metadataFetcher->fetch('https://example.test/')['status'], 'non-HTML response is refused');
$metadataNetwork->responses[] = metadataResponse('<title>Unsafe</title>', 200, null, 'text/html', 'gzip');
assertEquals('unavailable', $metadataFetcher->fetch('https://example.test/')['status'], 'encoded response is refused');
$metadataNetwork->responses[] = null;
assertEquals('unavailable', $metadataFetcher->fetch('https://example.test/')['status'], 'network failure is non-fatal');

$realNetwork = new CurlMetadataNetwork(PHP_BINARY);
if ($realNetwork->isAvailable()) {
    $slowResolver = tempnam(sys_get_temp_dir(), 'noel-metadata-resolver-');
    assertTrue(is_string($slowResolver), 'create a temporary resolver fixture');
    try {
        file_put_contents($slowResolver, '#!' . PHP_BINARY . "\n<?php usleep(10000000);\n");
        chmod($slowResolver, 0700);
        $start = hrtime(true) / 1e9;
        assertEquals(null, (new CurlMetadataNetwork($slowResolver))->resolve('example.test', $start + 0.2), 'slow DNS process fails closed');
        assertTrue(hrtime(true) / 1e9 - $start < 1.5, 'DNS process is terminated at the deadline');
    } finally {
        unlink($slowResolver);
    }
}
