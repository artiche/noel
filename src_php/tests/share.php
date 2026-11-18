<?php

declare(strict_types=1);

use Noel\Api\Domain\ShareDraftService;
use Noel\Api\Domain\ShareTokenService;
use Noel\Api\Http\App;
use Noel\Api\Http\ApiException;
use Noel\Api\Http\ShareMultipart;
use Noel\Api\Infrastructure\ShareDraftStore;
use Noel\Api\Infrastructure\OpenGraphFetcher;

$sharePrefix = sys_get_temp_dir() . '/noel-share-' . bin2hex(random_bytes(6));
$shareUsers = $sharePrefix . '-users.json';
$shareConstraints = $sharePrefix . '-constraints.json';
$shareTokens = $sharePrefix . '-tokens.json';
$shareDraftDirectory = $sharePrefix . '-drafts';
$shareWishlistDirectory = $sharePrefix . '-wishlists';
$quotaDirectory = $sharePrefix . '-quota';
$shareHash = password_hash('password', PASSWORD_DEFAULT);
file_put_contents($shareUsers, json_encode(['users' => [
    ['login' => 'alice', 'passwordHash' => $shareHash, 'email' => 'alice@example.test'],
    ['login' => 'bob', 'passwordHash' => $shareHash, 'email' => 'bob@example.test'],
    ['login' => 'other', 'passwordHash' => $shareHash, 'email' => 'other@example.test'],
    ['login' => 'admin', 'passwordHash' => $shareHash, 'email' => 'admin@example.test', 'isAdmin' => true],
]]));
file_put_contents($shareConstraints, json_encode([
    'users' => ['Alice', 'Bob'],
    'forbiddenRecipientsByGiver' => ['Alice' => [], 'Bob' => []],
]));
file_put_contents($sharePrefix . '-history.json', json_encode(['years' => (object) []]));
$draftService = new ShareDraftService(new ShareDraftStore($shareDraftDirectory));
$shareApp = new App($repo, $service, $authService, $passwordResetService, 'noel_session', 1800, '/',
    $shareUsers, $sharePrefix . '-resets.json', '/login', $shareConstraints, $sharePrefix . '-history.json',
    $shareWishlistDirectory, null, 'https://example.test/noel', $draftService,
    new ShareTokenService($repo, $shareTokens));
$origin = ['origin' => 'https://example.test', 'secure' => true, 'remoteAddress' => '192.0.2.1'];
$multipart = ['contentType' => 'multipart/form-data; boundary=example', 'remoteAddress' => '192.0.2.1',
    'secure' => true, 'body' => "--example\r\nContent-Disposition: form-data; name=\"text\"\r\n\r\nVoir https://example.test/item\r\n--example--\r\n"];

try {
    $shareCookies = [];
    foreach (['alice', 'bob', 'other', 'admin'] as $login) {
        $response = $shareApp->handle('POST', '/login', [], ['login' => $login, 'password' => 'password']);
        $shareCookies[$login] = ['noel_session' => $response['cookies'][0]['value']];
    }

    $android = $shareApp->handle('POST', '/share-target', [], null, [], $multipart);
    assertEquals(303, $android['status'], 'Android share redirects without a session');
    assertEquals('no-store', $android['headers']['Cache-Control'], 'share redirect is not cached');
    assertEquals('no-referrer', $android['headers']['Referrer-Policy'], 'share redirect suppresses referrers');
    $fragment = (string) parse_url($android['headers']['Location'], PHP_URL_FRAGMENT);
    parse_str($fragment, $fragmentFields);
    $draftId = $fragmentFields['draft'];
    assertTrue(preg_match('/^[a-f0-9]{64}$/D', $draftId) === 1, 'draft identifier has sufficient entropy');
    assertEquals('/noel/partage', parse_url($android['headers']['Location'], PHP_URL_PATH), 'redirect respects base path');
    assertEquals(401, $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $draftId], [], $origin)['status'], 'draft requires a session');
    assertEquals(403, $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $draftId], $shareCookies['alice'], ['secure' => true])['status'], 'draft requires a same-origin request');
    assertEquals(403, $shareApp->handle('POST', '/share-target', [], null, [], array_merge($multipart, ['secure' => false]))['status'], 'sharing requires HTTPS');
    assertEquals(403, $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $draftId], $shareCookies['other'], $origin)['status'], 'draft requires a participant');
    assertEquals(401, $shareApp->handle('POST', '/share-drafts/status', [], ['draftId' => $draftId], [], $origin)['status'], 'confirmation status requires a session');
    assertEquals(403, $shareApp->handle('POST', '/share-drafts/status', [], ['draftId' => $draftId], $shareCookies['other'], $origin)['status'], 'confirmation status requires a participant');
    assertEquals(400, $shareApp->handle('POST', '/share-drafts/status', [], ['draftId' => 'invalid'], $shareCookies['alice'], $origin)['status'], 'confirmation status validates draft identifiers');
    assertEquals(false, $shareApp->handle('POST', '/share-drafts/status', [], ['draftId' => $draftId], $shareCookies['bob'], $origin)['body']['data']['confirmed'], 'unconfirmed draft has no confirmation marker');
    $opened = $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $draftId], $shareCookies['alice'], $origin);
    assertEquals('https://example.test/item', $opened['body']['data']['url'], 'URL is extracted from Android text');
    assertEquals('Voir', $opened['body']['data']['text'], 'remaining text is retained');
    assertEquals(403, $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $draftId], $shareCookies['bob'], $origin)['status'], 'claimed draft rejects another account');

    $fakeMetadata = new FakeMetadataNetwork();
    $metadataApp = new App($repo, $service, $authService, $passwordResetService, 'noel_session', 1800, '/',
        $shareUsers, $sharePrefix . '-resets.json', '/login', $shareConstraints, $sharePrefix . '-history.json',
        $shareWishlistDirectory, null, 'https://example.test/noel', $draftService,
        new ShareTokenService($repo, $shareTokens), new OpenGraphFetcher($fakeMetadata));
    assertEquals(401, $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId], [], $origin)['status'], 'metadata requires authentication');
    assertEquals(403, $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId], $shareCookies['other'], $origin)['status'], 'metadata requires a participant');
    assertEquals(403, $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId], $shareCookies['bob'], $origin)['status'], 'metadata requires draft ownership');
    assertEquals(403, $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId], $shareCookies['alice'], ['secure' => true])['status'], 'metadata requires same origin');
    assertEquals(400, $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId, 'url' => 'https://elsewhere.test/'], $shareCookies['alice'], $origin)['status'], 'metadata endpoint refuses client-selected URLs');
    assertEquals([], $fakeMetadata->requests, 'unauthorized requests never reach the network');
    $fakeMetadata->onRequest = static function () use ($shareUsers): void {
        $lock = fopen($shareUsers . '.lock', 'c');
        assertTrue(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'metadata network request must not hold user lock');
        fclose($lock);
    };
    $fakeMetadata->responses[] = metadataResponse('<meta property="og:title" content="Un titre">');
    $metadata = $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId], $shareCookies['alice'], $origin);
    assertEquals(['status' => 'found', 'title' => 'Un titre', 'source' => 'opengraph'], $metadata['body']['data'], 'metadata endpoint returns the suggestion');
    assertEquals('no-store', $metadata['headers']['Cache-Control'], 'metadata response is not cached');
    assertEquals('', $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $draftId], $shareCookies['alice'], $origin)['body']['data']['title'], 'suggestion does not mutate the draft');
    $fakeMetadata->responses[] = null;
    assertEquals('unavailable', $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId], $shareCookies['alice'], $origin)['body']['data']['status'], 'network failure does not invalidate draft');
    assertEquals('unavailable', $shareApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId], $shareCookies['alice'], $origin)['body']['data']['status'], 'disabled metadata is explicit');
    $otherDraft = $shareApp->handle('POST', '/share-target', [], null, [], $multipart);
    parse_str((string) parse_url($otherDraft['headers']['Location'], PHP_URL_FRAGMENT), $otherFragment);
    $otherDraftId = $otherFragment['draft'];
    $fakeMetadata->onRequest = static function () use ($shareApp, $shareCookies, $origin, $otherDraftId): void {
        assertEquals(200, $shareApp->handle('POST', '/share-drafts/cancel', [], ['draftId' => $otherDraftId], $shareCookies['alice'], $origin)['status'], 'draft can be cancelled during metadata lookup');
    };
    $fakeMetadata->responses[] = metadataResponse('<title>Stale</title>');
    assertEquals(404, $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $otherDraftId], $shareCookies['alice'], $origin)['status'], 'draft is revalidated after network request');
    $titledDraft = $draftService->create(['title' => 'Déjà donné', 'url' => 'https://example.test/'], null,
        'android', ['login' => 'alice', 'accountId' => null], time());
    $beforeRequests = count($fakeMetadata->requests);
    assertEquals('not_needed', $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $titledDraft['draftId']], $shareCookies['alice'], $origin)['body']['data']['status'], 'existing title needs no metadata');
    assertEquals($beforeRequests, count($fakeMetadata->requests), 'existing title does not trigger a network request');
    $fakeMetadata->onRequest = null;
    $rateOrigin = array_merge($origin, ['remoteAddress' => '192.0.2.2']);
    for ($i = 0; $i < 20; $i++) {
        $fakeMetadata->responses[] = metadataResponse('<title>Title</title>');
        assertEquals(200, $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId], $shareCookies['alice'], $rateOrigin)['status'], 'metadata is available within the rate limit');
    }
    $beforeRequests = count($fakeMetadata->requests);
    assertEquals(429, $metadataApp->handle('POST', '/share-drafts/metadata', [], ['draftId' => $draftId], $shareCookies['alice'], $rateOrigin)['status'], 'metadata lookups are rate limited');
    assertEquals($beforeRequests, count($fakeMetadata->requests), 'rate-limited lookup does not reach the network');

    $wishlist = $shareApp->handle('GET', '/wishlist', [], null, $shareCookies['alice']);
    $revision = $wishlist['body']['data']['revision'];
    assertEquals([['insert' => "\n"]], $wishlist['body']['data']['ops'], 'receiving a share does not edit wishlist');
    assertEquals(409, $shareApp->handle('POST', '/share-drafts/confirm', [], [
        'draftId' => $draftId, 'revision' => 'stale',
    ], $shareCookies['alice'], $origin)['status'], 'stale wishlist revision is rejected');
    $confirmed = $shareApp->handle('POST', '/share-drafts/confirm', [], [
        'draftId' => $draftId, 'revision' => $revision, 'text' => 'Mon commentaire',
    ], $shareCookies['alice'], $origin);
    assertEquals(200, $confirmed['status'], 'draft confirmation succeeds');
    assertEquals(false, $confirmed['body']['data']['alreadyConfirmed'], 'first confirmation is recorded');
    $afterConfirm = $shareApp->handle('GET', '/wishlist', [], null, $shareCookies['alice']);
    $leftoverStore = new ShareDraftStore($shareDraftDirectory);
    $leftoverStore->locked(fn () => $leftoverStore->create($draftId, [
        'title' => '', 'url' => 'https://example.test/item', 'text' => 'Voir', 'image' => null,
        'source' => 'android', 'owner' => ['login' => 'alice', 'accountId' => null],
        'expiresAt' => time() + 3600,
    ]));
    $retry = $shareApp->handle('POST', '/share-drafts/confirm', [], [
        'draftId' => $draftId, 'revision' => $revision,
    ], $shareCookies['alice'], $origin);
    assertEquals(true, $retry['body']['data']['alreadyConfirmed'], 'retry is idempotent after interrupted draft cleanup');
    assertEquals(true, $shareApp->handle('POST', '/share-drafts/status', [], ['draftId' => $draftId], $shareCookies['alice'], $origin)['body']['data']['confirmed'], 'owner can recover a lost confirmation');
    assertEquals(false, $shareApp->handle('POST', '/share-drafts/status', [], ['draftId' => $draftId], $shareCookies['bob'], $origin)['body']['data']['confirmed'], 'confirmation status does not reveal another account’s result');
    assertEquals(404, $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $draftId], $shareCookies['alice'], $origin)['status'], 'retry removes the leftover draft');
    assertEquals($afterConfirm['body']['data']['ops'], $shareApp->handle('GET', '/wishlist', [], null, $shareCookies['alice'])['body']['data']['ops'], 'retry does not duplicate content');
    assertEquals(200, $shareApp->handle('PUT', '/wishlist', [], [
        'ops' => $afterConfirm['body']['data']['ops'], 'revision' => $afterConfirm['body']['data']['revision'],
    ], $shareCookies['alice'])['status'], 'normal wishlist save succeeds');
    assertEquals(true, $shareApp->handle('POST', '/share-drafts/confirm', [], [
        'draftId' => $draftId, 'revision' => $revision,
    ], $shareCookies['alice'], $origin)['body']['data']['alreadyConfirmed'], 'normal wishlist save preserves idempotency markers');
    assertEquals(404, $shareApp->handle('POST', '/share-drafts/confirm', [], [
        'draftId' => $draftId, 'revision' => $revision,
    ], $shareCookies['bob'], $origin)['status'], 'other account cannot use confirmation marker');

    $issued = $shareApp->handle('POST', '/share-tokens', [], [], $shareCookies['alice'], $origin);
    assertEquals(200, $issued['status'], 'participant creates a personal token');
    $token = $issued['body']['data']['token'];
    $tokenId = $issued['body']['data']['id'];
    assertTrue(!str_contains((string) file_get_contents($shareTokens), $token), 'token is stored only as a hash');
    assertEquals(401, $shareApp->handle('GET', '/share-tokens', [], null, [], $origin)['status'], 'listing keys requires a session');
    $listed = $shareApp->handle('GET', '/share-tokens', [], null, $shareCookies['alice'], $origin);
    assertEquals('no-store', $listed['headers']['Cache-Control'], 'key list is not cached');
    assertEquals(200, $shareApp->handle('GET', '/share-tokens', [], null, $shareCookies['alice'], ['secure' => true])['status'], 'same-origin GET does not require an Origin header');
    assertEquals(403, $shareApp->handle('POST', '/share-tokens', [], [], $shareCookies['alice'], ['secure' => true])['status'], 'key creation requires a valid Origin');
    assertEquals($tokenId, $listed['body']['data']['tokens'][0]['id'], 'owner can list the issued key');
    assertTrue(is_int($listed['body']['data']['tokens'][0]['createdAt']), 'new key has a creation date');
    assertEquals(['id', 'createdAt'], array_keys($listed['body']['data']['tokens'][0]), 'listing never exposes token material');
    $legacyTokens = $repo->loadShareTokens($shareTokens);
    unset($legacyTokens['tokens'][0]['createdAt']);
    $repo->saveShareTokens($shareTokens, $legacyTokens);
    assertEquals(null, $shareApp->handle('GET', '/share-tokens', [], null, $shareCookies['alice'], $origin)['body']['data']['tokens'][0]['createdAt'], 'legacy key without creation date remains usable');
    assertEquals([], $shareApp->handle('GET', '/share-tokens', [], null, $shareCookies['bob'], $origin)['body']['data']['tokens'], 'another account cannot list the key');
    assertEquals(404, $shareApp->handle('POST', '/share-tokens/revoke', [], ['id' => $tokenId], $shareCookies['bob'], $origin)['status'], 'another account cannot revoke the key');
    $iosRequest = $multipart;
    $iosRequest['authorization'] = 'Bearer ' . $token;
    assertEquals(401, $shareApp->handle('GET', '/wishlist', [], null, [], $iosRequest)['status'], 'share token cannot read wishlist');
    assertEquals(401, $shareApp->handle('GET', '/admin/users', [], null, [], $iosRequest)['status'], 'share token cannot access administration');
    $ios = $shareApp->handle('POST', '/wish', [], null, [], $iosRequest);
    assertEquals(201, $ios['status'], 'iOS token creates a draft');
    assertTrue(abs($ios['body']['data']['expiresAt'] - time() - 7 * 86400) <= 1, 'draft expires after seven days');
    $iosId = $ios['body']['data']['draftId'];
    assertEquals('draft=' . $iosId, parse_url($ios['body']['data']['confirmationUrl'], PHP_URL_FRAGMENT), 'iOS confirmation uses a fragment');
    assertEquals(403, $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $iosId], $shareCookies['bob'], $origin)['status'], 'iOS draft is restricted to its token owner');
    assertEquals(200, $shareApp->handle('POST', '/share-drafts/cancel', [], ['draftId' => $iosId], $shareCookies['alice'], $origin)['status'], 'owner can cancel a draft');
    assertEquals(404, $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $iosId], $shareCookies['alice'], $origin)['status'], 'cancelled draft is unavailable');
    file_put_contents($shareConstraints, json_encode(['users' => ['Bob'], 'forbiddenRecipientsByGiver' => ['Bob' => []]]));
    assertEquals(403, $shareApp->handle('POST', '/wish', [], null, [], $iosRequest)['status'], 'token requires a current participant');
    assertEquals($tokenId, $shareApp->handle('GET', '/share-tokens', [], null, $shareCookies['alice'], $origin)['body']['data']['tokens'][0]['id'], 'former participant can still see their keys');
    assertEquals(200, $shareApp->handle('POST', '/share-tokens/revoke', [], ['id' => $tokenId], $shareCookies['alice'], $origin)['status'], 'former participant can revoke their key');
    file_put_contents($shareConstraints, json_encode(['users' => ['Alice', 'Bob'], 'forbiddenRecipientsByGiver' => ['Alice' => [], 'Bob' => []]]));
    assertEquals(401, $shareApp->handle('POST', '/wish', [], null, [], $iosRequest)['status'], 'revoked token is rejected');
    $replacement = $shareApp->handle('POST', '/share-tokens', [], [], $shareCookies['alice'], $origin);
    $iosRequest['authorization'] = 'Bearer ' . $replacement['body']['data']['token'];
    $passwordChange = $shareApp->handle('POST', '/account', [], [
        'currentPassword' => 'password', 'newPassword' => 'new-password',
    ], $shareCookies['alice']);
    assertEquals(200, $passwordChange['status'], 'account password change succeeds');
    assertEquals(401, $shareApp->handle('POST', '/wish', [], null, [], $iosRequest)['status'], 'password change revokes personal token');
    $shareCookies['alice'] = ['noel_session' => $passwordChange['cookies'][0]['value']];
    assertEquals([], $shareApp->handle('GET', '/share-tokens', [], null, $shareCookies['alice'], $origin)['body']['data']['tokens'], 'revoked keys are absent after a password change');

    $bobToken = $shareApp->handle('POST', '/share-tokens', [], [], $shareCookies['bob'], $origin)['body']['data']['token'];
    $bobRequest = array_merge($multipart, ['authorization' => 'Bearer ' . $bobToken]);
    assertEquals(200, $shareApp->handle('DELETE', '/admin/users', [], ['login' => 'bob'], $shareCookies['admin'])['status'], 'admin deletes account');
    assertEquals(200, $shareApp->handle('POST', '/admin/users', [], ['login' => 'bob', 'email' => 'bob@example.test', 'isAdmin' => false], $shareCookies['admin'])['status'], 'admin recreates account');
    assertEquals(401, $shareApp->handle('POST', '/wish', [], null, [], $bobRequest)['status'], 'old key remains revoked after account recreation with the same session version');
    assertEquals(200, $shareApp->handle('PATCH', '/admin/users', [], ['login' => 'bob', 'password' => 'bob-password'], $shareCookies['admin'])['status'], 'admin sets account password');
    $bobLogin = $shareApp->handle('POST', '/login', [], ['login' => 'bob', 'password' => 'bob-password']);
    $shareCookies['bob'] = ['noel_session' => $bobLogin['cookies'][0]['value']];
    $bobToken = $shareApp->handle('POST', '/share-tokens', [], [], $shareCookies['bob'], $origin)['body']['data']['token'];
    $bobRequest['authorization'] = 'Bearer ' . $bobToken;
    assertEquals(200, $shareApp->handle('PATCH', '/admin/users', [], ['login' => 'bob', 'password' => 'another-password'], $shareCookies['admin'])['status'], 'admin changes password');
    assertEquals(401, $shareApp->handle('POST', '/wish', [], null, [], $bobRequest)['status'], 'admin password change revokes keys');
    $bobLogin = $shareApp->handle('POST', '/login', [], ['login' => 'bob', 'password' => 'another-password']);
    $shareCookies['bob'] = ['noel_session' => $bobLogin['cookies'][0]['value']];
    $bobToken = $shareApp->handle('POST', '/share-tokens', [], [], $shareCookies['bob'], $origin)['body']['data']['token'];
    $bobRequest['authorization'] = 'Bearer ' . $bobToken;
    assertEquals(200, $shareApp->handle('POST', '/password/forgot', [], ['login' => 'bob'])['status'], 'password reset is requested');
    $resetMessage = end($passwordResetNotifier->sentEmails);
    parse_str((string) parse_url($resetMessage['confirmationUrl'], PHP_URL_QUERY), $resetParams);
    assertEquals(302, $shareApp->handle('GET', '/password/confirm', ['token' => $resetParams['token']], null)['status'], 'password reset is confirmed');
    assertEquals(401, $shareApp->handle('POST', '/wish', [], null, [], $bobRequest)['status'], 'confirmed password reset revokes keys');
    $bobLogin = $shareApp->handle('POST', '/login', [], ['login' => 'bob', 'password' => $resetMessage['temporaryPassword']]);
    $shareCookies['bob'] = ['noel_session' => $bobLogin['cookies'][0]['value']];
    for ($i = 0; $i < 10; $i++) {
        $created = $shareApp->handle('POST', '/share-tokens', [], [], $shareCookies['bob'], $origin);
        assertEquals(200, $created['status'], 'up to ten active keys are allowed');
    }
    assertEquals(429, $shareApp->handle('POST', '/share-tokens', [], [], $shareCookies['bob'], $origin)['status'], 'an eleventh active key is rejected');
    assertEquals(200, $shareApp->handle('POST', '/share-tokens/revoke', [], ['id' => $created['body']['data']['id']], $shareCookies['bob'], $origin)['status'], 'revoking a key frees a slot');
    assertEquals(200, $shareApp->handle('POST', '/share-tokens', [], [], $shareCookies['bob'], $origin)['status'], 'a new key can use the freed slot');

    $expired = $draftService->create(['text' => 'expired'], null, 'android', null, time() - 7 * 86400);
    assertEquals(410, $shareApp->handle('POST', '/share-drafts/open', [], ['draftId' => $expired['draftId']], $shareCookies['alice'], $origin)['status'], 'expired draft cannot be opened');
    assertTrue($draftService->cleanup(time()) >= 1, 'expired draft is physically removed');
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    assertTrue(str_starts_with($draftService->imageFromBytes($image), 'data:image/png;base64,'), 'real PNG is accepted');
    $maxImage = $image . str_repeat("\0", 5_000_000 - strlen($image));
    assertTrue(str_starts_with($draftService->imageFromBytes($maxImage), 'data:image/png;base64,'), 'image of exactly 5 MB is accepted');
    unset($maxImage);
    try {
        $draftService->imageFromBytes('not an image');
        throw new RuntimeException('Invalid image accepted');
    } catch (ApiException $exception) {
        assertEquals('invalid_image', $exception->errorCode, 'invalid image is rejected');
    }
    $invalidUpload = $multipart;
    $invalidUpload['body'] = "--example\r\nContent-Disposition: form-data; name=\"image\"; filename=\"a.png\"\r\n\r\none"
        . "\r\n--example\r\nContent-Disposition: form-data; name=\"image\"; filename=\"b.png\"\r\n\r\ntwo\r\n--example--\r\n";
    assertEquals(400, $shareApp->handle('POST', '/share-target', [], null, [], $invalidUpload)['status'], 'multiple uploaded images are rejected');
    $parser = new ShareMultipart();
    $parsed = $parser->parse('multipart/form-data; boundary=example',
        "--example\r\nContent-Disposition: form-data; name=\"text\"\r\n\r\nline\r\n--example-extra\r\n--example--\r\n");
    assertEquals("line\r\n--example-extra", $parsed['fields']['text'], 'boundary prefixes inside content do not split a part');
    try {
        $parser->parse('multipart/form-data; boundary=example', "not a multipart body");
        throw new RuntimeException('Multipart with the wrong opening boundary accepted');
    } catch (ApiException $exception) {
        assertEquals(
            'Multipart body does not start with the boundary declared in Content-Type',
            $exception->getMessage(),
            'multipart opening boundary failure explains its cause'
        );
    }
    try {
        $parser->parse('multipart/form-data; boundary=example',
            "--example\r\nContent-Disposition: form-data; name=\"text\"\r\n\r\none");
        throw new RuntimeException('Multipart without a closing boundary accepted');
    } catch (ApiException $exception) {
        assertEquals(
            'Multipart body has no valid closing boundary',
            $exception->getMessage(),
            'multipart closing boundary failure explains its cause'
        );
    }
    try {
        $draftService->imageFromBytes(str_repeat('x', 5_000_001));
        throw new RuntimeException('Oversized image accepted');
    } catch (ApiException $exception) {
        assertEquals(413, $exception->statusCode, 'oversized image is rejected');
    }
    for ($i = 0; $i < 20; $i++) {
        $draftService->limit('192.0.2.99', time());
    }
    try {
        $draftService->limit('192.0.2.99', time());
        throw new RuntimeException('Rate limit was not enforced');
    } catch (ApiException $exception) {
        assertEquals(429, $exception->statusCode, 'share creation rate is limited');
    }
    $quotaStore = new ShareDraftStore($quotaDirectory);
    $quotaStore->locked(function () use ($quotaStore): void {
        for ($i = 0; $i < 128; $i++) {
            $quotaStore->create(str_pad(dechex($i), 64, '0', STR_PAD_LEFT), ['expiresAt' => time() + 3600]);
        }
        try {
            $quotaStore->create(str_repeat('f', 64), ['expiresAt' => time() + 3600]);
            throw new RuntimeException('Draft quota was not enforced');
        } catch (ApiException $exception) {
            assertEquals(429, $exception->statusCode, 'draft count is limited');
        }
    });
} finally {
    foreach ([$shareDraftDirectory, $shareWishlistDirectory, $quotaDirectory] as $directory) {
        if (is_dir($directory)) {
            foreach (array_diff(scandir($directory), ['.', '..']) as $file) {
                unlink($directory . '/' . $file);
            }
            rmdir($directory);
        }
    }
    foreach ([$shareUsers, $shareUsers . '.lock', $shareConstraints, $sharePrefix . '-history.json', $sharePrefix . '-resets.json', $shareTokens, $shareTokens . '.tmp'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
