<?php

declare(strict_types=1);

use Noel\Api\Domain\WishlistNotifier;
use Noel\Api\Http\App;
use Noel\Api\Http\ApiException;

final class FakeWishlistNotifier implements WishlistNotifier
{
    public array $messages = [];
    public bool $fail = false;

    public function sendWishlistEmail(string $giverLogin, string $email, string $participant, string $url): void
    {
        if ($this->fail) {
            throw new ApiException(500, 'mail_send_failed', 'Test mail failure');
        }
        $this->messages[] = compact('giverLogin', 'email', 'participant', 'url');
    }
}

$prefix = sys_get_temp_dir() . '/noel-wishlist-' . bin2hex(random_bytes(6));
$usersPath = $prefix . '-users.json';
$resetsPath = $prefix . '-resets.json';
$constraintsPath = $prefix . '-constraints.json';
$historyPath = $prefix . '-history.json';
$wishlistDir = $prefix . '-documents';
$year = (int) date('Y');
$hash = password_hash('password', PASSWORD_DEFAULT);
file_put_contents($usersPath, json_encode(['users' => [
    ['login' => 'admin', 'passwordHash' => $hash, 'email' => 'admin@example.com', 'isAdmin' => true],
    ['login' => 'beatrice', 'passwordHash' => $hash, 'email' => 'bea@example.com'],
    ['login' => 'bob', 'passwordHash' => $hash, 'email' => 'bob@example.com'],
    ['login' => 'claire', 'passwordHash' => $hash, 'email' => 'claire@example.com'],
]]));
file_put_contents($resetsPath, json_encode(['resets' => []]));
file_put_contents($constraintsPath, json_encode([
    'users' => ['Béatrice', 'Bob', 'Claire'],
    'forbiddenRecipientsByGiver' => ['Béatrice' => [], 'Bob' => [], 'Claire' => []],
]));
file_put_contents($historyPath, json_encode(['years' => [
    $year => ['Béatrice' => 'Bob', 'Bob' => 'Claire', 'Claire' => 'Beatrice'],
    $year + 1 => ['Béatrice' => 'Claire', 'Claire' => 'Bob', 'Bob' => 'Beatrice'],
]]));
$notifier = new FakeWishlistNotifier();
$wishlistApp = new App($repo, $service, $authService, $passwordResetService, 'noel_session', 1800, '/',
    $usersPath, $resetsPath, '/login', $constraintsPath, $historyPath, $wishlistDir, $notifier, 'https://example.test/noel/');

try {
    $cookies = [];
    foreach (['admin', 'beatrice', 'bob', 'claire'] as $login) {
        $response = $wishlistApp->handle('POST', '/login', [], ['login' => $login, 'password' => 'password']);
        $cookies[$login] = ['noel_session' => $response['cookies'][0]['value']];
    }
    assertEquals(401, $wishlistApp->handle('GET', '/wishlist', [], null)['status'], 'wishlist requires authentication');
    assertEquals(403, $wishlistApp->handle('GET', '/wishlist', [], null, $cookies['admin'])['status'], 'non participant cannot access wishlist');
    $own = $wishlistApp->handle('GET', '/wishlist', [], null, $cookies['beatrice']);
    assertEquals(200, $own['status'], 'participant accesses own wishlist');
    assertEquals('no-store', $own['headers']['Cache-Control'], 'wishlist is not cached');
    assertEquals(['name' => 'Claire', 'key' => 'claire', 'year' => $year], $own['body']['data']['recipient'], 'latest non-future draw is selected');
    $revision = $own['body']['data']['revision'];
    $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    $ops = [['insert' => 'Cadeau', 'attributes' => ['bold' => true]], ['insert' => "\n"],
        ['insert' => ['image' => $image]], ['insert' => "\n"]];
    $save = $wishlistApp->handle('PUT', '/wishlist', [], ['ops' => $ops, 'revision' => $revision], $cookies['beatrice']);
    assertEquals(200, $save['status'], 'own wishlist and embedded image are saved');
    assertEquals(409, $wishlistApp->handle('PUT', '/wishlist', [], ['ops' => $ops, 'revision' => $revision], $cookies['beatrice'])['status'], 'stale revision is rejected');
    assertEquals(403, $wishlistApp->handle('GET', '/wishlist/recipient', ['target' => 'beatrice'], null, $cookies['claire'])['status'], 'another participant cannot read the document or image');
    $received = $wishlistApp->handle('GET', '/wishlist/recipient', ['target' => 'beatrice'], null, $cookies['bob']);
    assertEquals($ops, $received['body']['data']['ops'], 'giver reads the targeted wishlist including images');
    assertEquals(200, $wishlistApp->handle('POST', '/wishlist/notify', [], [], $cookies['beatrice'])['status'], 'notification is sent to giver');
    assertEquals('bob@example.com', $notifier->messages[0]['email'], 'notification goes to giver account');
    assertEquals('https://example.test/noel/wishlist?target=beatrice', $notifier->messages[0]['url'], 'notification contains targeted subpath-safe link');
    $misconfiguredApp = new App($repo, $service, $authService, $passwordResetService, 'noel_session', 1800, '/',
        $usersPath, $resetsPath, '/login', $constraintsPath, $historyPath, $wishlistDir, $notifier, 'https://secret@example.test/noel');
    assertEquals(500, $misconfiguredApp->handle('POST', '/wishlist/notify', [], [], $cookies['beatrice'])['status'], 'frontend URL cannot contain credentials');
    assertEquals(1, count($notifier->messages), 'misconfigured link is never sent');
    $notifier->fail = true;
    assertEquals(500, $wishlistApp->handle('POST', '/wishlist/notify', [], [], $cookies['beatrice'])['status'], 'mail failure is reported');
    $notifier->fail = false;
    file_put_contents($historyPath, json_encode(['years' => []]));
    assertEquals(null, $wishlistApp->handle('GET', '/wishlist', [], null, $cookies['beatrice'])['body']['data']['recipient'], 'wishlist is editable when no draw exists');
    assertEquals(404, $wishlistApp->handle('POST', '/wishlist/notify', [], [], $cookies['beatrice'])['status'], 'notification requires a draw');
    file_put_contents($historyPath, json_encode(['years' => [
        $year - 1 => ['Béatrice' => 'Bob', 'Bob' => 'Claire', 'Claire' => 'Beatrice'],
        $year => ['Bob' => 'Claire', 'Claire' => 'Bob'],
    ]]));
    assertEquals(null, $wishlistApp->handle('GET', '/wishlist', [], null, $cookies['beatrice'])['body']['data']['recipient'], 'does not fall back when participant is absent from newest draw');
    assertEquals(403, $wishlistApp->handle('GET', '/wishlist/recipient', ['target' => 'beatrice'], null, $cookies['bob'])['status'], 'older assignments do not grant access');
    file_put_contents($historyPath, json_encode(['years' => [
        $year => ['Béatrice' => 'Bob', 'Bob' => 'Claire', 'Claire' => 'Beatrice'],
        $year + 1 => ['Béatrice' => 'Claire', 'Claire' => 'Bob', 'Bob' => 'Beatrice'],
    ]]));
    $newRevision = $save['body']['data']['revision'];
    foreach ([
        [['insert' => ['image' => 'javascript:alert(1)']]],
        [['insert' => '<script>', 'attributes' => ['link' => 'javascript:alert(1)']]],
        [['insert' => ['image' => 'data:image/svg+xml;base64,PHN2Zy8+']]],
        [['insert' => ['image' => 'data:image/png;base64,SGVsbG8=']]],
        [['insert' => ['video' => 'https://example.test/video']]],
        [['insert' => ['formula' => 'x']]],
    ] as $invalidOps) {
        assertEquals(400, $wishlistApp->handle('PUT', '/wishlist', [], ['ops' => $invalidOps, 'revision' => $newRevision], $cookies['beatrice'])['status'], 'unsafe content is rejected');
    }
    assertEquals(200, $wishlistApp->handle('PUT', '/wishlist', [], ['ops' => [['insert' => ['image' => 'https://example.test/image.png']], ['insert' => "\n"]], 'revision' => $newRevision], $cookies['beatrice'])['status'], 'external HTTPS image is accepted');
    assertEquals(200, $wishlistApp->handle('PATCH', '/admin/participants', [], ['name' => 'Béatrice', 'newName' => 'BEATRICE'], $cookies['admin'])['status'], 'participant spelling can change');
    assertEquals('BEATRICE', $wishlistApp->handle('GET', '/wishlist/recipient', ['target' => 'beatrice'], null, $cookies['bob'])['body']['data']['participant'], 'normalized identity preserves access after rename');
    file_put_contents($historyPath, json_encode(['years' => [$year => ['BEATRICE' => 'Claire', 'Claire' => 'Bob', 'Bob' => 'BEATRICE']]]));
    assertEquals(403, $wishlistApp->handle('GET', '/wishlist/recipient', ['target' => 'beatrice'], null, $cookies['bob'])['status'], 'old link stops working when giver changes');
    assertEquals(200, $wishlistApp->handle('GET', '/wishlist/recipient', ['target' => 'beatrice'], null, $cookies['claire'])['status'], 'new giver gets access');
    assertEquals(200, $wishlistApp->handle('DELETE', '/admin/users', [], ['login' => 'claire'], $cookies['admin'])['status'], 'giver account can be deleted');
    assertEquals(404, $wishlistApp->handle('POST', '/wishlist/notify', [], [], $cookies['beatrice'])['status'], 'missing giver account is reported');
    assertTrue($repo->loadWishlist($wishlistDir, 'beatrice') !== null, 'deleting another account does not delete wishlist');
    assertEquals(200, $wishlistApp->handle('POST', '/admin/users', [], ['login' => 'claire', 'email' => 'claire@example.com', 'isAdmin' => false], $cookies['admin'])['status'], 'giver account can be recreated');
    assertEquals(200, $wishlistApp->handle('PATCH', '/admin/users', [], ['login' => 'claire', 'password' => 'password'], $cookies['admin'])['status'], 'recreated giver can log in');
    $claireLogin = $wishlistApp->handle('POST', '/login', [], ['login' => 'claire', 'password' => 'password']);
    $cookies['claire'] = ['noel_session' => $claireLogin['cookies'][0]['value']];
    $currentRevision = $wishlistApp->handle('GET', '/wishlist', [], null, $cookies['beatrice'])['body']['data']['revision'];
    $png = base64_decode(substr($image, strpos($image, ',') + 1), true);
    $largeImage = 'data:image/png;base64,' . base64_encode($png . str_repeat("\0", 5_000_000 - strlen($png)));
    $largeOp = ['insert' => ['image' => $largeImage]];
    $maxOps = [$largeOp, $largeOp, $largeOp, $largeOp, ['insert' => "\n"]];
    $maxSave = $wishlistApp->handle('PUT', '/wishlist', [], ['ops' => $maxOps, 'revision' => $currentRevision], $cookies['beatrice']);
    assertEquals(200, $maxSave['status'], 'four 5 MB images meet the 20 MB limit');
    $overOps = [$largeOp, $largeOp, $largeOp, $largeOp, ['insert' => ['image' => $image]], ['insert' => "\n"]];
    assertEquals(413, $wishlistApp->handle('PUT', '/wishlist', [], ['ops' => $overOps, 'revision' => $maxSave['body']['data']['revision']], $cookies['beatrice'])['status'], 'total above 20 MB is rejected');
    $tooLarge = 'data:image/png;base64,' . base64_encode($png . str_repeat("\0", 5_000_001 - strlen($png)));
    assertEquals(413, $wishlistApp->handle('PUT', '/wishlist', [], ['ops' => [['insert' => ['image' => $tooLarge]]], 'revision' => $maxSave['body']['data']['revision']], $cookies['beatrice'])['status'], 'image above 5 MB is rejected');
    unset($largeImage, $largeOp, $maxOps, $overOps, $tooLarge);
    assertEquals(200, $wishlistApp->handle('DELETE', '/admin/users', [], ['login' => 'beatrice'], $cookies['admin'])['status'], 'owner account can be deleted');
    assertTrue($repo->loadWishlist($wishlistDir, 'beatrice') !== null, 'deleting owner account retains wishlist');
    assertEquals(200, $wishlistApp->handle('DELETE', '/admin/participants', [], ['name' => 'BEATRICE'], $cookies['admin'])['status'], 'participant can be deleted');
    assertEquals(null, $repo->loadWishlist($wishlistDir, 'beatrice'), 'participant deletion removes wishlist');
    assertEquals(403, $wishlistApp->handle('GET', '/wishlist/recipient', ['target' => 'beatrice'], null, $cookies['claire'])['status'], 'deleted participant wishlist is inaccessible');
} finally {
    foreach ([$usersPath, $resetsPath, $constraintsPath, $historyPath, $usersPath . '.lock',
        $wishlistDir . '/' . hash('sha256', 'beatrice') . '.json'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($wishlistDir)) {
        rmdir($wishlistDir);
    }
}
