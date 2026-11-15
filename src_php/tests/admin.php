<?php

declare(strict_types=1);

use Noel\Api\Domain\EdgeScoreCalculator;
use Noel\Api\Http\App;

$prefix = sys_get_temp_dir() . '/noel-admin-' . bin2hex(random_bytes(6));
$usersPath = $prefix . '-users.json';
$resetsPath = $prefix . '-resets.json';
$constraintsPath = $prefix . '-constraints.json';
$historyPath = $prefix . '-history.json';
$adminUsers = ['users' => [
    ['login' => 'admin', 'passwordHash' => password_hash('admin-password', PASSWORD_DEFAULT), 'email' => 'admin@example.com', 'isAdmin' => true],
    ['login' => 'beatrice', 'passwordHash' => password_hash('old-password', PASSWORD_DEFAULT), 'email' => 'bea@example.com', 'isAdmin' => false],
]];
file_put_contents($usersPath, json_encode($adminUsers));
file_put_contents($resetsPath, json_encode(['resets' => []]));
file_put_contents($constraintsPath, json_encode([
    'users' => ['Beatrice', 'Bob'],
    'forbiddenRecipientsByGiver' => ['Beatrice' => ['Bob'], 'Bob' => ['Beatrice']],
]));
file_put_contents($historyPath, json_encode(['years' => [
    2024 => ['Straße' => 'Alpha offre un cadeau à Beta', 'Alpha offre un cadeau à Beta' => 'Œ', 'Œ' => 'oe', 'oe' => 'Straße'],
    2025 => ['Beatrice' => 'Bob', 'Bob' => 'Beatrice'],
]]));
$adminApp = new App($repo, $service, $authService, $passwordResetService, 'noel_session', 1800, '/', $usersPath, $resetsPath, '/login', $constraintsPath, $historyPath);

try {
    $adminLogin = $adminApp->handle('POST', '/login', [], ['login' => 'admin', 'password' => 'admin-password']);
    $adminCookie = ['noel_session' => $adminLogin['cookies'][0]['value']];
    $beaLogin = $adminApp->handle('POST', '/login', [], ['login' => 'beatrice', 'password' => 'old-password']);
    $beaCookie = ['noel_session' => $beaLogin['cookies'][0]['value']];
    assertEquals('beatrice', $adminApp->handle('GET', '/session', [], null, $beaCookie)['body']['data']['participantKey'], 'participant key uses server normalization');
    assertEquals(null, $adminApp->handle('GET', '/session', [], null, $adminCookie)['body']['data']['participantKey'], 'unmatched account has no participant key');
    $unicodeDraw = $adminApp->handle('GET', '/draw/2024', [], null, $adminCookie)['body']['data'];
    $unicodeRows = array_column($unicodeDraw['assignments'], null, 'giver');
    assertEquals('strasse', $unicodeRows['Straße']['giverKey'], 'giver key uses Unicode case folding');
    assertEquals('œ', $unicodeRows['Œ']['giverKey'], 'distinct ligatures retain their identity');
    assertEquals('oe', $unicodeRows['oe']['giverKey'], 'distinct plain letters retain their identity');
    assertEquals('Alpha offre un cadeau à Beta', $unicodeRows['Alpha offre un cadeau à Beta']['giver'], 'structured names preserve phrase-like content');
    assertEquals(count($unicodeDraw['lines']), count($unicodeDraw['assignments']), 'structured rows match existing lines');
    foreach ($unicodeDraw['assignments'] as $index => $assignment) {
        assertEquals($unicodeDraw['lines'][$index], $assignment['giver'] . ' offre un cadeau à ' . $assignment['recipient'], 'structured rows preserve historical display order');
        assertEquals(Noel\Api\Domain\Identity::normalize($assignment['recipient']), $assignment['recipientKey'], 'recipient key uses server normalization');
    }
    assertEquals(401, $adminApp->handle('GET', '/admin/users', [], null)['status'], 'admin list requires session');
    assertEquals(403, $adminApp->handle('GET', '/admin/users', [], null, $beaCookie)['status'], 'admin list requires role');
    assertEquals(403, $adminApp->handle('POST', '/admin/participants', [], ['name' => 'Eve'], $beaCookie)['status'], 'participant write requires role');
    foreach ([
        ['POST', '/admin/users', ['login' => 'eve', 'email' => 'eve@example.com', 'isAdmin' => false]],
        ['PATCH', '/admin/users', ['login' => 'admin', 'isAdmin' => false]],
        ['DELETE', '/admin/users', ['login' => 'admin']],
        ['POST', '/admin/users/reset', ['login' => 'admin']],
        ['PATCH', '/admin/participants', ['name' => 'Bob', 'newName' => 'BOB']],
        ['DELETE', '/admin/participants', ['name' => 'Bob']],
    ] as [$method, $path, $body]) {
        assertEquals(403, $adminApp->handle($method, $path, [], $body, $beaCookie)['status'], 'admin operation requires current admin role');
    }
    $list = $adminApp->handle('GET', '/admin/users', [], null, $adminCookie);
    assertEquals('no-store', $list['headers']['Cache-Control'], 'admin list must not be cached');
    assertEquals('beatrice', $list['body']['data']['participants'][0]['login'], 'participant links to normalized login');
    assertEquals('bob', $list['body']['data']['participants'][1]['suggestedLogin'], 'default login is normalized');
    assertTrue(!str_contains(json_encode($list['body']), 'passwordHash'), 'admin list must not expose hashes');
    assertEquals(409, $adminApp->handle('DELETE', '/admin/users', [], ['login' => 'admin'], $adminCookie)['status'], 'last admin cannot be deleted');
    assertEquals(409, $adminApp->handle('PATCH', '/admin/users', [], ['login' => 'admin', 'isAdmin' => false], $adminCookie)['status'], 'last admin cannot be demoted');
    assertEquals(400, $adminApp->handle('POST', '/admin/users', [], ['login' => 'eve', 'email' => 'e@example.com', 'isAdmin' => false, 'participant' => 'Bob'], $adminCookie)['status'], 'linked login must match');
    $mailCount = count($passwordResetNotifier->sentEmails);
    assertEquals(200, $adminApp->handle('POST', '/admin/users', [], ['login' => 'bob', 'email' => 'bob@example.com', 'isAdmin' => true, 'participant' => 'Bob'], $adminCookie)['status'], 'linked account created');
    assertEquals($mailCount, count($passwordResetNotifier->sentEmails), 'creation does not send the generated password');
    assertEquals(409, $adminApp->handle('POST', '/admin/users', [], ['login' => 'BÓB', 'email' => 'b@example.com', 'isAdmin' => false], $adminCookie)['status'], 'normalized duplicate login rejected');
    assertEquals(401, $adminApp->handle('POST', '/login', [], ['login' => 'bob', 'password' => 'password'])['status'], 'generated password is not a known default');
    assertEquals(200, $adminApp->handle('PATCH', '/admin/users', [], ['login' => 'bob', 'password' => 'bob-password'], $adminCookie)['status'], 'admin can set password');
    $bobLogin = $adminApp->handle('POST', '/login', [], ['login' => 'bob', 'password' => 'bob-password']);
    $bobCookie = ['noel_session' => $bobLogin['cookies'][0]['value']];
    assertEquals(200, $adminApp->handle('PATCH', '/admin/users', [], ['login' => 'bob', 'isAdmin' => false], $adminCookie)['status'], 'admin can demote another admin');
    assertEquals(403, $adminApp->handle('GET', '/admin/users', [], null, $bobCookie)['status'], 'demotion applies to existing JWT immediately');
    assertEquals(false, $adminApp->handle('GET', '/session', [], null, $bobCookie)['body']['data']['isAdmin'], 'session reflects current role');
    assertEquals(200, $adminApp->handle('PATCH', '/admin/users', [], ['login' => 'bob', 'password' => 'other-password'], $adminCookie)['status'], 'admin password change succeeds');
    assertEquals(401, $adminApp->handle('GET', '/session', [], null, $bobCookie)['status'], 'manual password change revokes old sessions');
    assertEquals(200, $adminApp->handle('POST', '/admin/users/reset', [], ['login' => 'beatrice'], $adminCookie)['status'], 'admin can request reset');
    $mail = end($passwordResetNotifier->sentEmails);
    parse_str((string) parse_url($mail['confirmationUrl'], PHP_URL_QUERY), $resetParams);
    assertEquals(200, $adminApp->handle('DELETE', '/admin/users', [], ['login' => 'beatrice'], $adminCookie)['status'], 'account deletion succeeds');
    assertEquals(401, $adminApp->handle('GET', '/session', [], null, $beaCookie)['status'], 'deleted user session revoked');
    assertEquals(200, $adminApp->handle('POST', '/admin/users', [], ['login' => 'beatrice', 'email' => 'new@example.com', 'isAdmin' => false, 'participant' => 'Beatrice'], $adminCookie)['status'], 'same login can be recreated');
    assertEquals(401, $adminApp->handle('GET', '/session', [], null, $beaCookie)['status'], 'old session cannot authenticate recreated user');
    assertTrue($adminApp->handle('GET', '/password/confirm', ['token' => $resetParams['token']], null)['status'] !== 302, 'old reset cannot alter recreated user');
    $resets = $repo->loadPasswordResets($resetsPath);
    $lastReset = count($resets['resets']) - 1;
    $resets['resets'][$lastReset]['usedAt'] = null;
    $repo->savePasswordResets($resetsPath, $resets);
    assertEquals(401, $adminApp->handle('GET', '/password/confirm', ['token' => $resetParams['token']], null)['status'], 'old reset is bound to the deleted incarnation');
    $passwordResetNotifier->fail = true;
    assertEquals(500, $adminApp->handle('POST', '/admin/users/reset', [], ['login' => 'beatrice'], $adminCookie)['status'], 'mail failure is reported');
    $passwordResetNotifier->fail = false;
    assertEquals(200, $adminApp->handle('POST', '/admin/users/reset', [], ['login' => 'beatrice'], $adminCookie)['status'], 'mail can be retried');
    assertEquals(400, $adminApp->handle('PATCH', '/admin/participants', [], ['name' => 'Beatrice', 'newName' => 'Eve'], $adminCookie)['status'], 'different identity cannot rename participant');
    assertEquals(200, $adminApp->handle('PATCH', '/admin/participants', [], ['name' => 'Beatrice', 'newName' => 'Béatrice'], $adminCookie)['status'], 'equivalent spelling accepted');
    $constraints = $repo->loadConstraints($constraintsPath);
    assertEquals(['Béatrice'], $constraints['forbiddenRecipientsByGiver']['Bob'], 'rename updates references');
    assertEquals(['Bob'], $constraints['forbiddenRecipientsByGiver']['Béatrice'], 'rename preserves own constraints');
    assertEquals(200, $adminApp->handle('PATCH', '/admin/participants', [], ['name' => 'Bob', 'forbiddenRecipients' => []], $adminCookie)['status'], 'constraints can be edited');
    assertEquals(409, $adminApp->handle('POST', '/admin/participants', [], ['name' => 'BEATRICE'], $adminCookie)['status'], 'normalized participant collision rejected');
    assertEquals(200, $adminApp->handle('POST', '/admin/participants', [], ['name' => 'Eve'], $adminCookie)['status'], 'participant without account created');
    assertEquals(200, $adminApp->handle('PATCH', '/admin/participants', [], ['name' => 'Eve', 'newName' => 'Ève'], $adminCookie)['status'], 'participant without account can change equivalent spelling');
    assertEquals(400, $adminApp->handle('PATCH', '/admin/participants', [], ['name' => 'Ève', 'newName' => 'Alice'], $adminCookie)['status'], 'participant without account cannot change identity');
    assertEquals(400, $adminApp->handle('PATCH', '/admin/participants', [], ['name' => 'Bob', 'forbiddenRecipients' => ['Bob']], $adminCookie)['status'], 'participant cannot be their own forbidden recipient');
    $historyBefore = file_get_contents($historyPath);
    $drawBefore = $adminApp->handle('GET', '/draw/2025', [], null, $adminCookie);
    assertEquals(200, $drawBefore['status'], 'historical draw remains readable after rename and addition');
    assertTrue(in_array('Beatrice offre un cadeau à Bob', $drawBefore['body']['data']['lines'], true), 'historical spelling is preserved');
    $scores = new EdgeScoreCalculator();
    $seen = $scores->computeScore(2026, 'Béatrice', 'bob', ['years' => [2025 => ['Beatrice' => 'Bob', 'Bob' => 'Beatrice']]], 0);
    $fresh = $scores->computeScore(2026, 'Béatrice', 'bob', ['years' => []], 0);
    assertTrue($seen < $fresh, 'historical scoring recognizes accents and case changes');
    assertEquals($scores->computeScore(2026, 'Beatrice', 'Bob', ['years' => [2025 => ['Beatrice' => 'Bob', 'Bob' => 'Beatrice']]], 0), $seen, 'equivalent spellings keep the exact historical score');
    try {
        $service->validateHistoryYear(2025, ['Béatrice' => 'Bob', 'Beatrice' => 'Alice']);
        throw new RuntimeException('Ambiguous historical participants were accepted');
    } catch (Noel\Api\Http\ApiException $exception) {
        assertEquals('invalid_history', $exception->errorCode, 'historical identity collisions are rejected');
    }
    assertEquals(200, $adminApp->handle('GET', '/possible', ['year' => '2026'], null, $adminCookie)['status'], 'future draw accepts different historical participants');
    assertEquals(200, $adminApp->handle('DELETE', '/admin/participants', [], ['name' => 'Béatrice'], $adminCookie)['status'], 'participant deletion succeeds');
    assertEquals(200, $adminApp->handle('DELETE', '/admin/participants', [], ['name' => 'Bob'], $adminCookie)['status'], 'second participant deletion succeeds');
    assertEquals(200, $adminApp->handle('DELETE', '/admin/participants', [], ['name' => 'Eve'], $adminCookie)['status'], 'last participant can be removed');
    assertEquals([], $repo->loadConstraints($constraintsPath)['users'], 'empty participants remain valid');
    assertTrue(is_object(json_decode(file_get_contents($constraintsPath))->forbiddenRecipientsByGiver), 'empty constraints remain a JSON object');
    assertEquals(422, $adminApp->handle('GET', '/possible', ['year' => '2026'], null, $adminCookie)['status'], 'empty participant set cannot draw');
    assertEquals($drawBefore['body'], $adminApp->handle('GET', '/draw/2025', [], null, $adminCookie)['body'], 'historical draw unchanged after deletions');
    assertEquals($historyBefore, file_get_contents($historyPath), 'participant edits do not rewrite history');
    assertEquals(200, $adminApp->handle('POST', '/admin/participants', [], ['name' => '0'], $adminCookie)['status'], 'numeric participant can be created');
    assertEquals(200, $adminApp->handle('POST', '/admin/participants', [], ['name' => '1'], $adminCookie)['status'], 'second numeric participant can be created');
    $numericDraw = $adminApp->handle('POST', '/draw', [], ['year' => 2026, 'save' => true], $adminCookie);
    assertEquals(200, $numericDraw['status'], 'numeric participants can be drawn');
    assertTrue(is_object(json_decode(json_encode($numericDraw['body']))->data->assignmentsByGiver), 'draw assignments are a JSON object for numeric names');
    $numericHistory = json_decode(file_get_contents($historyPath));
    assertTrue(is_object($numericHistory->years->{'2026'}), 'saved history is a JSON object for numeric names');
    assertTrue(is_string($numericHistory->years->{'2026'}->{'0'}), 'saved giver remains a string');
    $readNumericDraw = json_decode(json_encode($adminApp->handle('GET', '/draw/2026', [], null, $adminCookie)['body']));
    assertTrue(is_object($readNumericDraw->data->assignmentsByGiver), 'historical numeric assignments are an object');
    assertTrue(is_string($readNumericDraw->data->assignmentsByGiver->{'0'}), 'historical numeric recipients remain strings');
    assertEquals('0', $readNumericDraw->data->assignments[0]->giver, 'numeric giver remains a string in structured rows');
    assertEquals('0', $readNumericDraw->data->assignments[0]->giverKey, 'numeric giver key remains a string');
    if (function_exists('pcntl_fork')) {
        assertEquals(200, $adminApp->handle('PATCH', '/admin/users', [], ['login' => 'bob', 'isAdmin' => true], $adminCookie)['status'], 'second admin can be promoted');
        $bobLogin = $adminApp->handle('POST', '/login', [], ['login' => 'bob', 'password' => 'other-password']);
        $bobCookie = ['noel_session' => $bobLogin['cookies'][0]['value']];
        $workers = [
            [$adminCookie, 'bob', $prefix . '-race-1'],
            [$bobCookie, 'admin', $prefix . '-race-2'],
        ];
        $pids = [];
        foreach ($workers as [$cookie, $target, $resultPath]) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork admin concurrency test');
            }
            if ($pid === 0) {
                $response = $adminApp->handle('PATCH', '/admin/users', [], ['login' => $target, 'isAdmin' => false], $cookie);
                file_put_contents($resultPath, (string) $response['status']);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'admin concurrency worker should succeed');
        }
        $statuses = [];
        foreach ($workers as [, , $resultPath]) {
            $statuses[] = (int) file_get_contents($resultPath);
            unlink($resultPath);
        }
        sort($statuses);
        assertEquals([200, 403], $statuses, 'concurrent demotions cannot remove the last admin');
        assertEquals(1, count(array_filter($repo->loadUsers($usersPath)['users'], static fn ($user) => $user['isAdmin'])), 'one admin survives concurrent demotions');
    }
} finally {
    $passwordResetNotifier->fail = false;
    foreach ([$usersPath, $resetsPath, $constraintsPath, $historyPath, $usersPath . '.lock', $prefix . '-race-1', $prefix . '-race-2'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
