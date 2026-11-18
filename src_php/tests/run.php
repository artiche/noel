<?php

declare(strict_types=1);

use Noel\Api\Domain\DrawService;
use Noel\Api\Domain\EdgeScoreCalculator;
use Noel\Api\Domain\AuthService;
use Noel\Api\Domain\JwtService;
use Noel\Api\Domain\PasswordResetNotifier;
use Noel\Api\Domain\PasswordResetService;
use Noel\Api\Domain\WeightedBipartiteMatcher;
use Noel\Api\Http\App;
use Noel\Api\Http\ApiException;
use Noel\Api\Infrastructure\JsonRepository;

require_once dirname(__DIR__) . '/src/Bootstrap.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertEquals(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

final class FakePasswordResetNotifier implements PasswordResetNotifier
{
    /** @var array<int, array<string,mixed>> */
    public array $sentEmails = [];
    public bool $fail = false;

    public function sendPasswordResetEmail(string $login, string $email, string $temporaryPassword, string $confirmationUrl, int $expiresAt): void
    {
        if ($this->fail) {
            throw new ApiException(500, 'mail_send_failed', 'Test mail delivery failed');
        }
        $this->sentEmails[] = [
            'login' => $login,
            'email' => $email,
            'temporaryPassword' => $temporaryPassword,
            'confirmationUrl' => $confirmationUrl,
            'expiresAt' => $expiresAt,
        ];
    }
}

$repo = new JsonRepository(dirname(__DIR__));
$service = new DrawService(new EdgeScoreCalculator(), new WeightedBipartiteMatcher());
foreach (['', str_repeat(' ', 40), 'CHANGE_ME_JWT_SECRET', 'CHANGE_ME_WITH_A_LONG_RANDOM_SECRET', 'short-secret'] as $weakSecret) {
    try {
        new JwtService($weakSecret);
        throw new RuntimeException('Weak JWT secret was accepted');
    } catch (ApiException $exception) {
        assertEquals('jwt_secret_invalid', $exception->errorCode, 'weak secret must be rejected');
    }
}
$jwtService = new JwtService('test-only-secret-not-for-production-123456789');
$authService = new AuthService($jwtService);
$passwordResetNotifier = new FakePasswordResetNotifier();
$passwordResetService = new PasswordResetService($jwtService, $passwordResetNotifier, 3600, 'https://example.test');
$app = new App($repo, $service, $authService, $passwordResetService, 'noel_session', 1800, '/');

$health = $app->handle('GET', '/health', [], null, []);
assertEquals(200, $health['status'], 'health endpoint should return 200');
assertEquals('ok', $health['body']['data']['status'], 'health endpoint should return ok');

$possibleWithoutToken = $app->handle('GET', '/possible', ['year' => '2027'], null, []);
assertEquals(401, $possibleWithoutToken['status'], 'possible endpoint without token should return 401');

$loginInvalid = $app->handle('POST', '/login', [], ['login' => 'admin', 'password' => 'wrong'], []);
assertEquals(401, $loginInvalid['status'], 'login with invalid password should return 401');

$login = $app->handle('POST', '/login', [], ['login' => 'admin', 'password' => 'noel123'], []);
assertEquals(200, $login['status'], 'login endpoint should return 200');
assertTrue(isset($login['cookies'][0]['value']), 'login should return session cookie');
$sessionToken = (string) $login['cookies'][0]['value'];
$claims = $jwtService->verifyToken($sessionToken, time());
assertEquals(true, $claims['isAdmin'], 'admin JWT should contain a boolean isAdmin');
assertTrue(!array_key_exists('email', $claims), 'JWT should not contain email');

$session = $app->handle('GET', '/session', [], null, ['noel_session' => $sessionToken]);
assertEquals(['authenticated' => true, 'isAdmin' => true, 'login' => 'admin', 'email' => 'admin@example.com', 'participant' => null, 'participantKey' => null], $session['body']['data'], 'session should expose account and optional participant');
assertEquals('no-store', $session['headers']['Cache-Control'], 'session response should not be cached');
assertEquals(200, $app->handle('POST', '/draw', [], ['year' => 2027], ['noel_session' => $sessionToken])['status'], 'admin should be allowed to create a draw');
assertEquals(401, $app->handle('POST', '/draw', [], ['year' => 2027], [])['status'], 'draw requires a session');
assertEquals(400, $app->handle('GET', '/possible', ['year' => '2027', 'historyFile' => '/tmp/history.json'], null, ['noel_session' => $sessionToken])['status'], 'possible must reject client-selected paths');
assertEquals(400, $app->handle('GET', '/draw/2026', ['constraintsFile' => '../data/users.json'], null, ['noel_session' => $sessionToken])['status'], 'draw read must reject client-selected paths');
assertEquals(400, $app->handle('POST', '/draw', [], ['year' => 2027, 'save' => true, 'historyFile' => '/tmp/history.json'], ['noel_session' => $sessionToken])['status'], 'draw write must reject client-selected paths');

foreach ([
    ['sub' => 'admin', 'exp' => time() + 3600],
    ['sub' => 'admin', 'isAdmin' => true, 'exp' => time() + 3600],
    ['sub' => 'admin', 'isAdmin' => 'true', 'sessionVersion' => 0, 'exp' => time() + 3600],
    ['sub' => 'admin', 'isAdmin' => true, 'sessionVersion' => 0, 'typ' => 'password_reset', 'exp' => time() + 3600],
    ['isAdmin' => true, 'sessionVersion' => 0, 'exp' => time() + 3600],
    ['sub' => 'unknown', 'isAdmin' => true, 'sessionVersion' => 0, 'exp' => time() + 3600],
    ['sub' => 'admin', 'isAdmin' => true, 'sessionVersion' => 0, 'exp' => time() - 1],
] as $invalidClaims) {
    $invalidToken = $jwtService->createToken($invalidClaims);
    assertEquals(401, $app->handle('GET', '/session', [], null, ['noel_session' => $invalidToken])['status'], 'invalid session claims must be rejected');
    assertEquals(401, $app->handle('POST', '/draw', [], ['year' => 2027], ['noel_session' => $invalidToken])['status'], 'invalid session must not create draws');
}

$subpathApp = new App($repo, $service, $authService, $passwordResetService, 'custom_session', 1800, '/noel/api');
$logout = $subpathApp->handle('POST', '/logout', [], null, ['custom_session' => $sessionToken]);
assertEquals(200, $logout['status'], 'logout should return 200');
assertEquals(false, $logout['body']['data']['authenticated'], 'logout should report logged out');
assertEquals('custom_session', $logout['cookies'][0]['name'], 'logout should use configured cookie name');
assertEquals('', $logout['cookies'][0]['value'], 'logout should clear the cookie value');
assertTrue($logout['cookies'][0]['expires'] < time(), 'logout cookie should expire in the past');
assertEquals('/noel/api', $logout['cookies'][0]['path'], 'logout should use configured cookie path');
assertEquals(true, $logout['cookies'][0]['secure'], 'logout cookie should remain secure');
assertEquals(true, $logout['cookies'][0]['httponly'], 'logout cookie should remain HttpOnly');
assertEquals('Strict', $logout['cookies'][0]['samesite'], 'logout cookie should remain SameSite Strict');

$possible = $app->handle('GET', '/possible', ['year' => '2027'], null, ['noel_session' => $sessionToken]);
assertEquals(200, $possible['status'], 'possible endpoint should return 200');
assertTrue(isset($possible['body']['data']['possibleRecipientsByGiver']['Maxime']), 'possible should include Maxime');

$printExisting = $app->handle('GET', '/draw/2026', [], null, ['noel_session' => $sessionToken]);
assertEquals(200, $printExisting['status'], 'print existing draw should return 200');
assertTrue(count($printExisting['body']['data']['lines']) > 0, 'print existing draw should return lines');

$printMissing = $app->handle('GET', '/draw/2099', [], null, ['noel_session' => $sessionToken]);
assertEquals(404, $printMissing['status'], 'missing draw should return 404');
assertEquals('draw_not_found', $printMissing['body']['error']['code'], 'missing draw code should be draw_not_found');

$tempUsersFile = sys_get_temp_dir() . '/noel-users-' . bin2hex(random_bytes(6)) . '.json';
$tempResetsFile = sys_get_temp_dir() . '/noel-resets-' . bin2hex(random_bytes(6)) . '.json';

$initialUsers = [
    'users' => [
        [
            'login' => 'admin',
            'passwordHash' => '$2y$12$Pmd9iXJsg7JrXlfQRf3L7.HQo1PQO1j/ZR1XZTiPeC9cbdlkfv.u.',
            'email' => 'admin@example.com',
            'isAdmin' => true,
        ],
    ],
];

file_put_contents($tempUsersFile, json_encode($initialUsers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
file_put_contents($tempResetsFile, json_encode(['resets' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
$testApp = new App($repo, $service, $authService, $passwordResetService, 'noel_session', 1800, '/', $tempUsersFile, $tempResetsFile);
$beforeReset = $testApp->handle('POST', '/login', [], ['login' => 'admin', 'password' => 'noel123'], []);
$oldSession = ['noel_session' => $beforeReset['cookies'][0]['value']];
assertEquals(400, $app->handle('POST', '/login', [], ['login' => 'admin', 'password' => 'noel123', 'usersFile' => $tempUsersFile], [])['status'], 'login must reject client-selected user files');
assertEquals(400, $app->handle('POST', '/password/forgot', [], ['login' => 'admin', 'usersFile' => $tempUsersFile], [])['status'], 'reset request must reject client-selected user files');
assertEquals(400, $app->handle('GET', '/password/confirm', ['token' => 'token', 'passwordResetsFile' => $tempResetsFile], null, [])['status'], 'reset confirmation must reject client-selected reset files');

$forgot = $testApp->handle(
    'POST',
    '/password/forgot',
    [],
    ['login' => 'admin'],
    []
);
assertEquals(200, $forgot['status'], 'password forgot endpoint should return 200');
assertTrue(($forgot['body']['data']['passwordResetRequested'] ?? false) === true, 'password forgot should report requested');
assertEquals(1, count($passwordResetNotifier->sentEmails), 'password forgot should send one email');

$oldUrl = $passwordResetNotifier->sentEmails[0]['confirmationUrl'];
parse_str((string) parse_url($oldUrl, PHP_URL_QUERY), $oldParams);
$secondForgot = $testApp->handle('POST', '/password/forgot', [], ['login' => 'admin'], []);
assertEquals(200, $secondForgot['status'], 'second reset request should succeed');
assertEquals(409, $testApp->handle('GET', '/password/confirm', ['token' => $oldParams['token']], null, [])['status'], 'new reset invalidates previous link');
assertEquals(200, $testApp->handle('GET', '/session', [], null, $oldSession)['status'], 'request alone does not revoke sessions');

$sent = $passwordResetNotifier->sentEmails[1];
assertTrue(str_contains((string) $sent['confirmationUrl'], 'token='), 'confirmation email should contain token query parameter');

$confirmationUrl = (string) $sent['confirmationUrl'];
$query = parse_url($confirmationUrl, PHP_URL_QUERY);
parse_str(is_string($query) ? $query : '', $params);
$token = $params['token'] ?? null;
assertTrue(is_string($token) && $token !== '', 'confirmation URL should contain a token');
assertEquals(401, $testApp->handle('GET', '/password/confirm', ['token' => $token . 'x'], null, [])['status'], 'modified reset token must be rejected');

$confirm = $testApp->handle(
    'GET',
    '/password/confirm',
    ['token' => (string) $token],
    null,
    []
);
assertEquals(302, $confirm['status'], 'password confirm should redirect');
assertEquals('/login', $confirm['headers']['Location'] ?? null, 'password confirm should redirect to login');
assertEquals(401, $testApp->handle('GET', '/session', [], null, $oldSession)['status'], 'reset confirmation revokes existing sessions');

$loginWithNewPassword = $testApp->handle(
    'POST',
    '/login',
    [],
    ['login' => 'admin', 'password' => (string) $sent['temporaryPassword']],
    []
);
assertEquals(200, $loginWithNewPassword['status'], 'login with temporary password after confirmation should succeed');
assertEquals(true, $repo->loadUsers($tempUsersFile)['users'][0]['isAdmin'], 'password reset must preserve admin role');
assertEquals(200, $testApp->handle('GET', '/session', [], null, ['noel_session' => $loginWithNewPassword['cookies'][0]['value']])['status'], 'new session remains valid');

$confirmAgain = $testApp->handle(
    'GET',
    '/password/confirm',
    ['token' => (string) $token],
    null,
    []
);
assertEquals(409, $confirmAgain['status'], 'reusing password confirm token should fail');
assertEquals('password_reset_already_used', $confirmAgain['body']['error']['code'], 'reused token code should match');

assertEquals(200, $testApp->handle('POST', '/password/forgot', [], ['login' => 'admin'], [])['status'], 'reset before mail failure should be issued');
$beforeFailureEmail = end($passwordResetNotifier->sentEmails);
parse_str((string) parse_url($beforeFailureEmail['confirmationUrl'], PHP_URL_QUERY), $beforeFailureParams);
$passwordResetNotifier->fail = true;
assertEquals(500, $testApp->handle('POST', '/password/forgot', [], ['login' => 'admin'], [])['status'], 'mail failure must be reported');
$passwordResetNotifier->fail = false;
assertEquals(409, $testApp->handle('GET', '/password/confirm', ['token' => $beforeFailureParams['token']], null, [])['status'], 'mail failure must not restore previous reset links');

if (function_exists('pcntl_fork')) {
    assertEquals(200, $testApp->handle('POST', '/password/forgot', [], ['login' => 'admin'], [])['status'], 'concurrent test reset should be issued');
    $raceEmail = end($passwordResetNotifier->sentEmails);
    parse_str((string) parse_url($raceEmail['confirmationUrl'], PHP_URL_QUERY), $raceParams);
    $children = [];
    $resultFiles = [$tempUsersFile . '.result1', $tempUsersFile . '.result2'];
    foreach ($resultFiles as $resultFile) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Cannot fork concurrent reset test');
        }
        if ($pid === 0) {
            $response = $testApp->handle('GET', '/password/confirm', ['token' => $raceParams['token']], null, []);
            file_put_contents($resultFile, (string) $response['status']);
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'concurrent reset worker should succeed');
    }
    $statuses = array_map(static fn ($path) => (int) file_get_contents($path), $resultFiles);
    sort($statuses);
    assertEquals([302, 409], $statuses, 'only one concurrent confirmation may succeed');
    foreach ($resultFiles as $resultFile) {
        unlink($resultFile);
    }
}

assertEquals(200, $testApp->handle('POST', '/password/forgot', [], ['login' => 'admin'], [])['status'], 'interrupted save test reset should be issued');
$lastEmail = end($passwordResetNotifier->sentEmails);
parse_str((string) parse_url($lastEmail['confirmationUrl'], PHP_URL_QUERY), $interruptedParams);
$updatedUsers = $repo->loadUsers($tempUsersFile);
$updatedUsers['users'][0]['sessionVersion']++;
$repo->saveUsers($tempUsersFile, $updatedUsers);
assertEquals(401, $testApp->handle('GET', '/password/confirm', ['token' => $interruptedParams['token']], null, [])['status'], 'stale reset cannot be used after user data is saved');

assertEquals(200, $testApp->handle('POST', '/password/forgot', [], ['login' => 'admin'], [])['status'], 'legacy reset test should issue a link');
$legacyEmail = end($passwordResetNotifier->sentEmails);
parse_str((string) parse_url($legacyEmail['confirmationUrl'], PHP_URL_QUERY), $legacyParams);
$legacyResets = $repo->loadPasswordResets($tempResetsFile);
$lastResetIndex = count($legacyResets['resets']) - 1;
$legacyResets['resets'][$lastResetIndex]['sessionVersion'] = null;
$repo->savePasswordResets($tempResetsFile, $legacyResets);
assertEquals(401, $testApp->handle('GET', '/password/confirm', ['token' => $legacyParams['token']], null, [])['status'], 'legacy reset without version must be rejected');

$initialUsers['users'][0]['isAdmin'] = false;
file_put_contents($tempUsersFile, json_encode($initialUsers));
$nonAdminLogin = $testApp->handle('POST', '/login', [], ['login' => 'admin', 'password' => 'noel123'], []);
assertEquals(200, $nonAdminLogin['status'], 'non-admin login should succeed');
$nonAdminCookie = ['noel_session' => $nonAdminLogin['cookies'][0]['value']];
assertEquals(false, $jwtService->verifyToken($nonAdminCookie['noel_session'], time())['isAdmin'], 'non-admin JWT should contain false');
assertEquals(false, $testApp->handle('GET', '/session', [], null, $nonAdminCookie)['body']['data']['isAdmin'], 'session should expose non-admin role');
assertEquals(403, $testApp->handle('POST', '/draw', [], ['year' => 2027], $nonAdminCookie)['status'], 'non-admin cannot create a draw');
assertEquals(200, $testApp->handle('GET', '/draw/2026', [], null, $nonAdminCookie)['status'], 'non-admin can still read draws');

unset($initialUsers['users'][0]['isAdmin']);
file_put_contents($tempUsersFile, json_encode($initialUsers));
assertEquals(false, $repo->loadUsers($tempUsersFile)['users'][0]['isAdmin'], 'missing role defaults to non-admin');
$legacyLogin = $testApp->handle('POST', '/login', [], ['login' => 'admin', 'password' => 'noel123'], []);
assertEquals(false, $jwtService->verifyToken($legacyLogin['cookies'][0]['value'], time())['isAdmin'], 'legacy user receives non-admin session');
foreach ([null, 'false', 0] as $invalidRole) {
    $initialUsers['users'][0]['isAdmin'] = $invalidRole;
    file_put_contents($tempUsersFile, json_encode($initialUsers));
    $invalidLogin = $testApp->handle('POST', '/login', [], ['login' => 'admin', 'password' => 'noel123'], []);
    assertEquals(422, $invalidLogin['status'], 'non-boolean role must be rejected');
}

$participantUser = [
    'login' => "Béatrice",
    'passwordHash' => password_hash('old-password', PASSWORD_DEFAULT),
    'email' => 'old@example.com',
    'isAdmin' => false,
];
file_put_contents($tempUsersFile, json_encode(['users' => [$participantUser]]));
$participantLogin = $testApp->handle('POST', '/login', [], ['login' => "BE\u{0301}ATRICE", 'password' => 'old-password'], []);
assertEquals(200, $participantLogin['status'], 'Unicode case and accents should not affect login');
$participantCookie = ['noel_session' => $participantLogin['cookies'][0]['value']];
assertEquals('Béatrice', $jwtService->verifyToken($participantCookie['noel_session'], time())['sub'], 'JWT uses canonical login');
assertEquals('Beatrice', $testApp->handle('GET', '/session', [], null, $participantCookie)['body']['data']['participant'], 'participant should match canonical draw spelling');
assertEquals('beatrice', $testApp->handle('GET', '/session', [], null, $participantCookie)['body']['data']['participantKey'], 'participant key ignores accents and case');
assertEquals(200, $testApp->handle('POST', '/password/forgot', [], ['login' => 'beatrice'], [])['status'], 'reset finds normalized login');
$resetMail = end($passwordResetNotifier->sentEmails);
parse_str((string) parse_url($resetMail['confirmationUrl'], PHP_URL_QUERY), $accountResetParams);
assertEquals('Béatrice', $resetMail['login'], 'reset uses canonical login');
assertEquals(401, $testApp->handle('POST', '/account', [], ['currentPassword' => 'old-password', 'email' => 'new@example.com'], [])['status'], 'account update requires authentication');
assertEquals(403, $testApp->handle('POST', '/account', [], ['currentPassword' => 'wrong', 'email' => 'new@example.com'], $participantCookie)['status'], 'wrong password must be rejected');
assertEquals('old@example.com', $repo->loadUsers($tempUsersFile)['users'][0]['email'], 'failed update must not change user');
assertEquals(400, $testApp->handle('POST', '/account', [], ['currentPassword' => 'old-password', 'email' => 'invalid'], $participantCookie)['status'], 'invalid email must be rejected');
assertEquals(400, $testApp->handle('POST', '/account', [], ['currentPassword' => 'old-password'], $participantCookie)['status'], 'empty update must be rejected');
assertEquals(400, $testApp->handle('POST', '/account', [], ['currentPassword' => 'old-password', 'newPassword' => str_repeat('a', 73)], $participantCookie)['status'], 'oversized password must be rejected');
assertEquals(400, $testApp->handle('POST', '/account', [], ['currentPassword' => 'old-password', 'newPassword' => "abc\0def"], $participantCookie)['status'], 'NUL password must be rejected');
assertEquals(200, $testApp->handle('POST', '/account', [], ['currentPassword' => 'old-password', 'email' => 'new@example.com'], $participantCookie)['status'], 'email update succeeds');
assertEquals('new@example.com', $testApp->handle('GET', '/session', [], null, $participantCookie)['body']['data']['email'], 'email update keeps session and updates email');
assertEquals(409, $testApp->handle('GET', '/password/confirm', ['token' => $accountResetParams['token']], null, [])['status'], 'email update invalidates old reset');
assertEquals(200, $testApp->handle('POST', '/password/forgot', [], ['login' => 'beatrice'], [])['status'], 'new reset can be requested');
$resetMail = end($passwordResetNotifier->sentEmails);
parse_str((string) parse_url($resetMail['confirmationUrl'], PHP_URL_QUERY), $accountResetParams);
$update = $testApp->handle('POST', '/account', [], ['currentPassword' => 'old-password', 'email' => 'final@example.com', 'newPassword' => 'new-password'], $participantCookie);
assertEquals(200, $update['status'], 'combined update succeeds');
assertEquals(401, $testApp->handle('GET', '/session', [], null, $participantCookie)['status'], 'old session is revoked');
$renewedCookie = ['noel_session' => $update['cookies'][0]['value']];
assertEquals('final@example.com', $testApp->handle('GET', '/session', [], null, $renewedCookie)['body']['data']['email'], 'renewed session remains usable');
assertTrue($testApp->handle('GET', '/password/confirm', ['token' => $accountResetParams['token']], null, [])['status'] !== 302, 'old reset cannot override account change');
assertEquals(401, $testApp->handle('POST', '/login', [], ['login' => 'beatrice', 'password' => 'old-password'], [])['status'], 'old password is invalid');
assertEquals(200, $testApp->handle('POST', '/login', [], ['login' => 'beatrice', 'password' => 'new-password'], [])['status'], 'new password works');
assertEquals(200, $testApp->handle('POST', '/password/forgot', [], ['login' => 'beatrice'], [])['status'], 'reset before password-only change should work');
$resetMail = end($passwordResetNotifier->sentEmails);
parse_str((string) parse_url($resetMail['confirmationUrl'], PHP_URL_QUERY), $accountResetParams);
$passwordOnly = $testApp->handle('POST', '/account', [], ['currentPassword' => 'new-password', 'newPassword' => 'another-password'], $renewedCookie);
assertEquals(200, $passwordOnly['status'], 'password-only update succeeds');
assertEquals(401, $testApp->handle('GET', '/session', [], null, $renewedCookie)['status'], 'password-only update revokes old session');
assertEquals(401, $testApp->handle('GET', '/password/confirm', ['token' => $accountResetParams['token']], null, [])['status'], 'password-only update revokes reset');
assertEquals(200, $testApp->handle('GET', '/session', [], null, ['noel_session' => $passwordOnly['cookies'][0]['value']])['status'], 'password-only update renews current session');
file_put_contents($tempUsersFile, json_encode(['users' => [$participantUser, array_merge($participantUser, ['login' => 'beatrice'])]]));
assertEquals(422, $testApp->handle('POST', '/login', [], ['login' => 'beatrice', 'password' => 'old-password'], [])['status'], 'normalized duplicate logins are rejected');
$tempConstraintsFile = $tempUsersFile . '.constraints';
file_put_contents($tempConstraintsFile, json_encode(['users' => ['Beatrice', 'Béatrice'], 'forbiddenRecipientsByGiver' => []]));
try {
    $repo->loadConstraints($tempConstraintsFile);
    throw new RuntimeException('Ambiguous participant names were accepted');
} catch (ApiException $exception) {
    assertEquals('invalid_constraints', $exception->errorCode, 'normalized duplicate participants are rejected');
}
unlink($tempConstraintsFile);

@unlink($tempUsersFile);
@unlink($tempResetsFile);
@unlink($tempUsersFile . '.lock');

require __DIR__ . '/admin.php';
require __DIR__ . '/wishlist.php';
require __DIR__ . '/metadata.php';
require __DIR__ . '/share.php';
require __DIR__ . '/http-share.php';

echo "All src_php tests passed.\n";
