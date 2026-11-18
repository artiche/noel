<?php

declare(strict_types=1);

if (!extension_loaded('curl') || !function_exists('proc_open')) {
    throw new RuntimeException('HTTP share tests require the PHP curl extension and proc_open.');
}

function shareHttpRequest(string $url, string $method = 'GET', mixed $body = null, array $headers = []): array
{
    $responseHeaders = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException('HTTP test request failed: ' . curl_error($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $response];
}

function shareHttpJson(string $url, string $method, array $body, string $cookie = ''): array
{
    return shareHttpRequest($url, $method, json_encode($body, JSON_THROW_ON_ERROR), array_filter([
        'Content-Type: application/json',
        'Origin: https://example.test',
        $cookie !== '' ? 'Cookie: ' . $cookie : '',
    ]));
}

function shareHttpDecode(array $response): array
{
    return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
}

function shareHttpRemoveFixture(string $directory): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

$fixture = sys_get_temp_dir() . '/noel-http-share-' . bin2hex(random_bytes(12));
if (!mkdir($fixture, 0700)) {
    throw new RuntimeException('Unable to create HTTP test fixture.');
}
$server = null;
try {
    mkdir($fixture . '/public', 0700);
    mkdir($fixture . '/data', 0700);
    if (!copy(dirname(__DIR__) . '/public/index.php', $fixture . '/public/index.php')
        || !symlink(dirname(__DIR__) . '/src', $fixture . '/src')) {
        throw new RuntimeException('Unable to prepare HTTP test fixture.');
    }
    $config = require dirname(__DIR__) . '/data/config.php';
    $config['base_path'] = '/noel/api';
    $config['frontend_url'] = 'https://example.test/noel';
    $config['jwt_secret'] = 'http-test-only-secret-not-for-production-123456789';
    $config['share_allow_insecure_local'] = true;
    $config['share_metadata_enabled'] = false;
    file_put_contents($fixture . '/data/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    $password = password_hash('password', PASSWORD_DEFAULT);
    file_put_contents($fixture . '/data/users.json', json_encode(['users' => [
        ['login' => 'alice', 'passwordHash' => $password, 'email' => 'alice@example.test'],
        ['login' => 'bob', 'passwordHash' => $password, 'email' => 'bob@example.test'],
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/data/constraints.json', json_encode([
        'users' => ['Alice', 'Bob'], 'forbiddenRecipientsByGiver' => ['Alice' => [], 'Bob' => []],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/data/history.json', '{"years":{}}');
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    file_put_contents($fixture . '/image.png', $image);

    $identity = bin2hex(random_bytes(16));
    $router = '<?php if (($_SERVER["REQUEST_URI"] ?? "") === "/__fixture_' . $identity . '") { echo "'
        . $identity . '"; return; } require __DIR__ . "/public/index.php";';
    file_put_contents($fixture . '/router.php', $router);

    $startServer = static function (int $postReading) use ($fixture, $identity): array {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException("Unable to allocate test port: $error");
        }
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-d', "enable_post_data_reading=$postReading",
            '-d', 'post_max_size=8M', '-S', "127.0.0.1:$port", $fixture . '/router.php'], [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $fixture . '/server.log', 'a'],
            2 => ['file', $fixture . '/server.log', 'a'],
        ], $pipes, $fixture);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start PHP test server.');
        }
        $base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            usleep(100_000);
            if (!proc_get_status($process)['running']) {
                break;
            }
            try {
                $response = shareHttpRequest($base . '/__fixture_' . $identity);
                if ($response['status'] === 200 && $response['body'] === $identity) {
                    return [$process, $base . '/noel/api'];
                }
            } catch (RuntimeException) {
                // The server may not yet be listening.
            }
        }
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('PHP test server failed to start or its port was occupied.');
    };

    [$server, $api] = $startServer(0);
    $login = shareHttpJson($api . '/login', 'POST', ['login' => 'alice', 'password' => 'password']);
    assertEquals(200, $login['status'], 'HTTP login succeeds');
    assertTrue(preg_match('/^(noel_session=[^;]+)/', $login['headers']['set-cookie'] ?? '', $match) === 1,
        'HTTP login returns a session cookie');
    $cookie = $match[1];

    $android = shareHttpRequest($api . '/share-target', 'POST', [
        'text' => 'Voir https://example.test/item',
        'image' => new CURLFile($fixture . '/image.png', 'image/png', 'image.png'),
    ]);
    assertEquals(303, $android['status'], 'real mixed multipart Android share redirects');
    assertEquals('no-store', $android['headers']['cache-control'] ?? '', 'HTTP redirect is not cached');
    assertEquals('/noel/partage', parse_url($android['headers']['location'] ?? '', PHP_URL_PATH), 'HTTP redirect respects base path');
    parse_str((string) parse_url($android['headers']['location'], PHP_URL_FRAGMENT), $fragment);
    $draft = $fragment['draft'] ?? '';
    assertTrue(preg_match('/^[a-f0-9]{64}$/D', $draft) === 1, 'HTTP redirect contains a draft capability');
    assertEquals(401, shareHttpJson($api . '/share-drafts/open', 'POST', ['draftId' => $draft])['status'],
        'HTTP draft requires authentication');
    $opened = shareHttpJson($api . '/share-drafts/open', 'POST', ['draftId' => $draft], $cookie);
    assertEquals(200, $opened['status'], 'HTTP draft opens with a session');
    $openedData = shareHttpDecode($opened)['data'];
    assertEquals('https://example.test/item', $openedData['url'], 'HTTP multipart extracts the URL');
    assertEquals('Voir', $openedData['text'], 'HTTP multipart preserves the remaining text');
    assertEquals('data:image/png;base64,' . base64_encode($image), $openedData['image'], 'HTTP multipart preserves image bytes');
    $bob = shareHttpJson($api . '/login', 'POST', ['login' => 'bob', 'password' => 'password']);
    assertEquals(200, $bob['status'], 'second HTTP login succeeds');
    assertTrue(preg_match('/^(noel_session=[^;]+)/', $bob['headers']['set-cookie'] ?? '', $bobMatch) === 1,
        'second HTTP login returns a session cookie');
    assertEquals(403, shareHttpJson($api . '/share-drafts/open', 'POST', ['draftId' => $draft], $bobMatch[1])['status'],
        'another account cannot open a claimed draft over HTTP');
    $wishlist = shareHttpRequest($api . '/wishlist', 'GET', null, ['Cookie: ' . $cookie]);
    assertEquals(200, $wishlist['status'], 'HTTP wishlist can be read');
    $revision = shareHttpDecode($wishlist)['data']['revision'];
    $saved = shareHttpJson($api . '/wishlist', 'PUT', [
        'revision' => $revision, 'ops' => [['insert' => "Existant\n"]],
    ], $cookie);
    assertEquals(200, $saved['status'], 'existing HTTP wishlist can be saved');
    assertEquals(409, shareHttpJson($api . '/share-drafts/confirm', 'POST', [
        'draftId' => $draft, 'revision' => $revision,
    ], $cookie)['status'], 'stale HTTP wishlist revision is rejected');
    $revision = shareHttpDecode($saved)['data']['revision'];
    $confirmation = shareHttpJson($api . '/share-drafts/confirm', 'POST', [
        'draftId' => $draft, 'revision' => $revision,
    ], $cookie);
    assertEquals(200, $confirmation['status'], 'HTTP confirmation succeeds');
    assertEquals(false, shareHttpDecode($confirmation)['data']['alreadyConfirmed'], 'first HTTP confirmation is recorded');
    $repeated = shareHttpJson($api . '/share-drafts/confirm', 'POST', [
        'draftId' => $draft, 'revision' => $revision,
    ], $cookie);
    assertEquals(true, shareHttpDecode($repeated)['data']['alreadyConfirmed'], 'HTTP confirmation is idempotent');
    $after = shareHttpRequest($api . '/wishlist', 'GET', null, ['Cookie: ' . $cookie]);
    $ops = json_encode(shareHttpDecode($after)['data']['ops'], JSON_THROW_ON_ERROR);
    assertTrue(str_contains($ops, 'Existant') && str_contains($ops, 'Voir')
        && str_contains($ops, base64_encode($image)), 'HTTP confirmation preserves existing content and adds the share');

    $tokenResponse = shareHttpJson($api . '/share-tokens', 'POST', [], $cookie);
    assertEquals(200, $tokenResponse['status'], 'HTTP token creation succeeds');
    $tokenData = shareHttpDecode($tokenResponse)['data'];
    $ios = shareHttpRequest($api . '/wish', 'POST', [
        'image' => new CURLFile($fixture . '/image.png', 'image/png', 'image.png'),
    ], ['Authorization: Bearer ' . $tokenData['token']]);
    assertEquals(201, $ios['status'], 'real image-only multipart iOS share succeeds');
    $iosData = shareHttpDecode($ios)['data'];
    assertEquals('/noel/partage', parse_url($iosData['confirmationUrl'], PHP_URL_PATH), 'iOS confirmation URL respects base path');
    assertEquals('draft=' . $iosData['draftId'], parse_url($iosData['confirmationUrl'], PHP_URL_FRAGMENT), 'iOS response includes its capability');
    assertEquals(200, shareHttpJson($api . '/share-tokens/revoke', 'POST', ['id' => $tokenData['id']], $cookie)['status'],
        'HTTP token revocation succeeds');
    assertEquals(401, shareHttpRequest($api . '/wish', 'POST', ['text' => 'test'],
        ['Authorization: Bearer ' . $tokenData['token']])['status'], 'revoked token is rejected over HTTP');

    $duplicate = "--test-boundary\r\nContent-Disposition: form-data; name=\"text\"\r\n\r\none\r\n"
        . "--test-boundary\r\nContent-Disposition: form-data; name=\"text\"\r\n\r\ntwo\r\n--test-boundary--\r\n";
    $invalid = shareHttpRequest($api . '/share-target', 'POST', $duplicate, ['Content-Type: multipart/form-data; boundary=test-boundary']);
    assertEquals(400, $invalid['status'], 'duplicate multipart fields are rejected over HTTP');
    assertEquals('invalid_multipart', shareHttpDecode($invalid)['error']['code'], 'duplicate error is explicit');
    assertEquals("Duplicate multipart field 'text'", shareHttpDecode($invalid)['error']['message'],
        'duplicate multipart error explains its cause over HTTP');
    $duplicateImages = "--test-boundary\r\nContent-Disposition: form-data; name=\"image\"; filename=\"one.png\"\r\n"
        . "Content-Type: image/png\r\n\r\n" . $image . "\r\n--test-boundary\r\n"
        . "Content-Disposition: form-data; name=\"image\"; filename=\"two.png\"\r\n"
        . "Content-Type: image/png\r\n\r\n" . $image . "\r\n--test-boundary--\r\n";
    assertEquals(400, shareHttpRequest($api . '/share-target', 'POST', $duplicateImages,
        ['Content-Type: multipart/form-data; boundary=test-boundary'])['status'], 'multiple images are rejected over HTTP');
    file_put_contents($fixture . '/fake.png', 'not an image');
    $fake = shareHttpRequest($api . '/share-target', 'POST', [
        'image' => new CURLFile($fixture . '/fake.png', 'image/png', 'fake.png'),
    ]);
    assertEquals(400, $fake['status'], 'fraudulent image MIME is rejected over HTTP');
    assertEquals('invalid_image', shareHttpDecode($fake)['error']['code'], 'fraudulent image error is explicit');
    file_put_contents($fixture . '/large.png', $image . str_repeat('x', 5_000_001 - strlen($image)));
    $largeImage = shareHttpRequest($api . '/share-target', 'POST', [
        'image' => new CURLFile($fixture . '/large.png', 'image/png', 'large.png'),
    ]);
    assertEquals(413, $largeImage['status'], 'HTTP image limit is enforced below the multipart limit');
    assertEquals('image_too_large', shareHttpDecode($largeImage)['error']['code'], 'oversized image error is explicit');
    $tooLarge = shareHttpRequest($api . '/share-target', 'POST', str_repeat('x', 5_200_001),
        ['Content-Type: multipart/form-data; boundary=test-boundary']);
    assertEquals(413, $tooLarge['status'], 'HTTP front controller enforces multipart size limit');
    assertEquals('share_too_large', shareHttpDecode($tooLarge)['error']['code'], 'oversized request error is explicit');

    proc_terminate($server);
    proc_close($server);
    $server = null;
    [$server, $api] = $startServer(1);
    $misconfigured = shareHttpRequest($api . '/share-target', 'POST', ['text' => 'test']);
    assertEquals(503, $misconfigured['status'], 'HTTP multipart rejects incorrect PHP configuration');
    assertEquals('share_multipart_unavailable', shareHttpDecode($misconfigured)['error']['code'], 'configuration error is explicit');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    shareHttpRemoveFixture($fixture);
}
