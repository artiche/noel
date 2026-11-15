<?php

declare(strict_types=1);

namespace Noel\Api\Http;

use Throwable;
use Noel\Api\Domain\AuthService;
use Noel\Api\Domain\AdminService;
use Noel\Api\Domain\DrawService;
use Noel\Api\Domain\Identity;
use Noel\Api\Domain\PasswordResetService;
use Noel\Api\Domain\WishlistNotifier;
use Noel\Api\Domain\WishlistService;
use Noel\Api\Infrastructure\JsonRepository;

final class App
{
    public function __construct(
        private readonly JsonRepository $jsonRepository,
        private readonly DrawService $drawService,
        private readonly AuthService $authService,
        private readonly PasswordResetService $passwordResetService,
        private readonly string $cookieName,
        private readonly int $jwtTtlSeconds,
        private readonly string $cookiePath,
        private readonly string $usersFilePath = 'data/users.json',
        private readonly string $passwordResetsFilePath = 'data/passwordResets.json',
        private readonly string $loginRedirectUrl = '/login',
        private readonly string $constraintsFilePath = 'data/constraints.json',
        private readonly string $historyFilePath = 'data/history.json',
        private readonly ?string $wishlistDirectory = null,
        private readonly ?WishlistNotifier $wishlistNotifier = null,
        private readonly string $frontendUrl = ''
    ) {
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, string> $cookies
     * @return array{status: int, body: array<string,mixed>, cookies?: array<int, array<string,mixed>>, headers?: array<string,string>}
     */
    public function handle(string $method, string $path, array $query, ?array $jsonBody, array $cookies = []): array
    {
        try {
            if ($method === 'GET' && $path === '/health') {
                return $this->ok(['status' => 'ok']);
            }

            if ($method === 'POST' && $path === '/login') {
                if ($jsonBody === null) {
                    throw new ApiException(400, 'invalid_body', 'JSON body is required');
                }

                $login = $this->readRequiredString($jsonBody, 'login');
                $password = $this->readRequiredString($jsonBody, 'password');
                $this->rejectAuthFilePaths($jsonBody);

                $usersData = $this->jsonRepository->loadUsers($this->usersFilePath);
                $auth = $this->authService->authenticate($login, $password, $usersData, time(), $this->jwtTtlSeconds);

                return $this->okWithCookies(
                    [
                        'authenticated' => true,
                        'expiresAt' => $auth['exp'],
                    ],
                    [
                        $this->buildSessionCookie($auth['token'], $auth['exp']),
                    ]
                );
            }

            if ($method === 'POST' && $path === '/logout') {
                return $this->okWithCookies(
                    ['authenticated' => false],
                    [$this->buildSessionCookie('', time() - 3600)]
                );
            }

            if ($method === 'POST' && $path === '/password/forgot') {
                if ($jsonBody === null) {
                    throw new ApiException(400, 'invalid_body', 'JSON body is required');
                }

                $login = $this->readRequiredString($jsonBody, 'login');
                $this->rejectAuthFilePaths($jsonBody);

                $this->jsonRepository->withUserLock($this->usersFilePath, function () use ($login): void {
                    $usersData = $this->jsonRepository->loadUsers($this->usersFilePath);
                    $resetsData = $this->jsonRepository->loadPasswordResets($this->passwordResetsFilePath);
                    $updated = $this->passwordResetService->requestReset($login, $usersData, $resetsData, time());
                    $this->jsonRepository->savePasswordResets($this->passwordResetsFilePath, ['resets' => $updated['resets']]);
                    $this->passwordResetService->sendNotification($updated['notification']);
                });

                return $this->ok([
                    'passwordResetRequested' => true,
                ]);
            }

            if ($method === 'GET' && $path === '/password/confirm') {
                $token = $this->readRequiredString($query, 'token');
                $this->rejectAuthFilePaths($query);

                $this->jsonRepository->withUserLock($this->usersFilePath, function () use ($token): void {
                    $usersData = $this->jsonRepository->loadUsers($this->usersFilePath);
                    $resetsData = $this->jsonRepository->loadPasswordResets($this->passwordResetsFilePath);
                    $updated = $this->passwordResetService->confirmReset($token, $usersData, $resetsData, time());
                    $this->jsonRepository->savePasswordResets($this->passwordResetsFilePath, ['resets' => $updated['resets']]);
                    $this->jsonRepository->saveUsers($this->usersFilePath, ['users' => $updated['users']]);
                });

                return $this->redirect($this->loginRedirectUrl);
            }

            $session = $this->assertAuthenticated($cookies);

            if ($method === 'GET' && $path === '/session') {
                $users = $this->jsonRepository->loadUsers($this->usersFilePath)['users'];
                $user = null;
                foreach ($users as $candidate) {
                    if ($candidate['login'] === $session['sub']) {
                        $user = $candidate;
                        break;
                    }
                }
                if ($user === null) {
                    throw new ApiException(401, 'invalid_token', 'Session has been revoked');
                }
                $participant = null;
                foreach ($this->jsonRepository->loadConstraints($this->constraintsFilePath)['users'] as $name) {
                    if (Identity::normalize($name) === Identity::normalize($user['login'])) {
                        $participant = $name;
                        break;
                    }
                }
                $response = $this->ok([
                    'authenticated' => true,
                    'isAdmin' => $session['isAdmin'],
                    'login' => $user['login'],
                    'email' => $user['email'],
                    'participant' => $participant,
                    'participantKey' => $participant === null ? null : Identity::normalize($participant),
                ]);
                $response['headers'] = ['Cache-Control' => 'no-store'];
                return $response;
            }

            if ($method === 'POST' && $path === '/account') {
                if ($jsonBody === null) {
                    throw new ApiException(400, 'invalid_body', 'JSON body is required');
                }
                $this->rejectAuthFilePaths($jsonBody);
                $currentPassword = $this->readRequiredString($jsonBody, 'currentPassword');
                $email = array_key_exists('email', $jsonBody) ? $this->readRequiredString($jsonBody, 'email') : null;
                $newPassword = array_key_exists('newPassword', $jsonBody) ? $this->readRequiredString($jsonBody, 'newPassword') : null;
                if ($email === null && $newPassword === null) {
                    throw new ApiException(400, 'invalid_input', 'Email or new password is required');
                }
                if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new ApiException(400, 'invalid_input', 'Invalid email');
                }
                if ($newPassword !== null && (strlen($newPassword) > 72 || str_contains($newPassword, "\0"))) {
                    throw new ApiException(400, 'invalid_input', 'New password must not contain NUL or exceed 72 bytes');
                }
                $updatedAuth = $this->jsonRepository->withUserLock($this->usersFilePath, function () use ($cookies, $currentPassword, $email, $newPassword): ?array {
                    $currentSession = $this->assertAuthenticated($cookies);
                    $users = $this->jsonRepository->loadUsers($this->usersFilePath);
                    foreach ($users['users'] as &$user) {
                        if ($user['login'] !== $currentSession['sub']) {
                            continue;
                        }
                        if (!password_verify($currentPassword, $user['passwordHash'])) {
                            throw new ApiException(403, 'invalid_current_password', 'Current password is incorrect');
                        }
                        if ($email === $user['email'] && $newPassword === null) {
                            throw new ApiException(400, 'invalid_input', 'No changes requested');
                        }
                        if ($newPassword !== null) {
                            if ($user['sessionVersion'] === PHP_INT_MAX) {
                                throw new ApiException(500, 'session_version_exhausted', 'Cannot increment session version');
                            }
                            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                            if (!is_string($hash) || $hash === '') {
                                throw new ApiException(500, 'password_hash_failed', 'Failed to hash password');
                            }
                            $user['passwordHash'] = $hash;
                            $user['sessionVersion']++;
                        }
                        if ($email !== null && $email !== $user['email']) {
                            $resets = $this->jsonRepository->loadPasswordResets($this->passwordResetsFilePath);
                            foreach ($resets['resets'] as &$reset) {
                                if ($reset['login'] === $user['login'] && $reset['usedAt'] === null) {
                                    $reset['usedAt'] = time();
                                }
                            }
                            unset($reset);
                            $this->jsonRepository->savePasswordResets($this->passwordResetsFilePath, $resets);
                            $user['email'] = $email;
                        }
                        $auth = $newPassword === null ? null : $this->authService->createSession($user, time(), $this->jwtTtlSeconds);
                        unset($user);
                        $this->jsonRepository->saveUsers($this->usersFilePath, $users);
                        return $auth;
                    }
                    unset($user);
                    throw new ApiException(401, 'invalid_token', 'Session has been revoked');
                });
                $response = $updatedAuth === null
                    ? $this->ok(['updated' => true])
                    : $this->okWithCookies(['updated' => true], [$this->buildSessionCookie($updatedAuth['token'], $updatedAuth['exp'])]);
                $response['headers'] = ['Cache-Control' => 'no-store'];
                return $response;
            }

            if (str_starts_with($path, '/admin/')) {
                if ($session['isAdmin'] !== true) {
                    throw new ApiException(403, 'forbidden', 'Administrator access required');
                }
                $this->rejectAuthFilePaths($query);
                $this->readDataPaths($query, $jsonBody);
                if ($jsonBody !== null) {
                    $this->rejectAuthFilePaths($jsonBody);
                }
                $result = $this->jsonRepository->withUserLock($this->usersFilePath, function () use ($cookies, $method, $path, $jsonBody): array {
                    $this->assertAdmin($cookies);
                    $admin = new AdminService($this->jsonRepository, $this->usersFilePath, $this->passwordResetsFilePath, $this->constraintsFilePath, $this->wishlistDirectory);
                    if ($method === 'GET' && $path === '/admin/users') {
                        return $admin->list();
                    }
                    if ($jsonBody === null) {
                        throw new ApiException(400, 'invalid_body', 'JSON body is required');
                    }
                    if ($path === '/admin/users') {
                        if ($method === 'POST') {
                            $admin->createUser($jsonBody);
                        } elseif ($method === 'PATCH') {
                            $admin->updateUser($jsonBody);
                        } elseif ($method === 'DELETE') {
                            $admin->deleteUser($this->readRequiredString($jsonBody, 'login'));
                        } else {
                            throw new ApiException(404, 'not_found', 'Route not found');
                        }
                    } elseif ($method === 'POST' && $path === '/admin/users/reset') {
                        $login = $this->readRequiredString($jsonBody, 'login');
                        $users = $this->jsonRepository->loadUsers($this->usersFilePath);
                        $resets = $this->jsonRepository->loadPasswordResets($this->passwordResetsFilePath);
                        $updated = $this->passwordResetService->requestReset($login, $users, $resets, time());
                        $this->jsonRepository->savePasswordResets($this->passwordResetsFilePath, ['resets' => $updated['resets']]);
                        $this->passwordResetService->sendNotification($updated['notification']);
                    } elseif ($path === '/admin/participants') {
                        if ($method === 'POST') {
                            $admin->createParticipant($this->readRequiredString($jsonBody, 'name'));
                        } elseif ($method === 'PATCH') {
                            $admin->updateParticipant($jsonBody);
                        } elseif ($method === 'DELETE') {
                            $admin->deleteParticipant($this->readRequiredString($jsonBody, 'name'));
                        } else {
                            throw new ApiException(404, 'not_found', 'Route not found');
                        }
                    } else {
                        throw new ApiException(404, 'not_found', 'Route not found');
                    }
                    return ['updated' => true];
                });
                $response = $this->ok($result);
                $response['headers'] = ['Cache-Control' => 'no-store'];
                return $response;
            }

            if ($path === '/wishlist' || str_starts_with($path, '/wishlist/')) {
                if ($this->wishlistDirectory === null) {
                    throw new ApiException(500, 'wishlist_config_missing', 'Wishlist storage is not configured');
                }
                $this->rejectAuthFilePaths($query);
                $this->readDataPaths($query, $jsonBody);
                $result = $this->jsonRepository->withUserLock($this->usersFilePath, function () use ($cookies, $method, $path, $query, $jsonBody): array {
                    $session = $this->assertAuthenticated($cookies);
                    $service = new WishlistService(
                        $this->jsonRepository, $this->drawService, $this->wishlistDirectory,
                        $this->constraintsFilePath, $this->historyFilePath, $this->usersFilePath,
                        $this->wishlistNotifier, $this->frontendUrl
                    );
                    if ($method === 'GET' && $path === '/wishlist') {
                        return $service->own($session['sub']);
                    }
                    if ($method === 'GET' && $path === '/wishlist/recipient') {
                        return $service->recipient($session['sub'], $this->readRequiredString($query, 'target'));
                    }
                    if ($method === 'PUT' && $path === '/wishlist' && $jsonBody !== null) {
                        return $service->save($session['sub'], $jsonBody);
                    }
                    if ($method === 'POST' && $path === '/wishlist/notify') {
                        $service->notifyGiver($session['sub']);
                        return ['sent' => true];
                    }
                    throw new ApiException(404, 'not_found', 'Route not found');
                });
                $response = $this->ok($result);
                $response['headers'] = ['Cache-Control' => 'no-store'];
                return $response;
            }

            if ($method === 'GET' && $path === '/possible') {
                $year = $this->readYearFromQuery($query);
                $seed = $this->readOptionalInt($query, 'seed');
                [$constraintsPath, $historyPath] = $this->readDataPaths($query, null);

                $constraints = $this->jsonRepository->loadConstraints($constraintsPath);
                $history = $this->jsonRepository->loadHistory($historyPath);
                $possible = $this->drawService->getPossibleRecipients($year, $constraints, $history, $seed);

                return $this->ok([
                    'year' => $year,
                    'possibleRecipientsByGiver' => array_is_list($possible) ? (object) $possible : $possible,
                ]);
            }

            if ($method === 'GET' && preg_match('#^/draw/(\d{4})$#', $path, $matches) === 1) {
                $year = (int) $matches[1];
                [, $historyPath] = $this->readDataPaths($query, null);
                $history = $this->jsonRepository->loadHistory($historyPath);

                $yearDraw = $history['years'][$year] ?? null;
                if ($yearDraw === null) {
                    throw new ApiException(404, 'draw_not_found', "No draw found for year {$year}");
                }
                $this->drawService->validateHistoryYear($year, $yearDraw);

                $giverToRecipient = [];
                foreach ($yearDraw as $recipient => $giver) {
                    $giverToRecipient[$giver] = (string) $recipient;
                }
                ksort($giverToRecipient, SORT_STRING);

                $lines = [];
                $assignments = [];
                foreach ($giverToRecipient as $giver => $recipient) {
                    $lines[] = "{$giver} offre un cadeau à {$recipient}";
                    $assignments[] = [
                        'giver' => (string) $giver,
                        'recipient' => $recipient,
                        'giverKey' => Identity::normalize((string) $giver),
                        'recipientKey' => Identity::normalize($recipient),
                    ];
                }

                return $this->ok([
                    'year' => $year,
                    'assignmentsByGiver' => array_is_list($giverToRecipient) ? (object) $giverToRecipient : $giverToRecipient,
                    'lines' => $lines,
                    'assignments' => $assignments,
                ]);
            }

            if ($method === 'POST' && $path === '/draw') {
                if ($session['isAdmin'] !== true) {
                    throw new ApiException(403, 'forbidden', 'Administrator access required');
                }
                if ($jsonBody === null) {
                    throw new ApiException(400, 'invalid_body', 'JSON body is required');
                }

                $year = $this->readRequiredInt($jsonBody, 'year');
                $seed = $this->readOptionalInt($jsonBody, 'seed');
                $save = $this->readOptionalBool($jsonBody, 'save') ?? false;
                [$constraintsPath, $historyPath] = $this->readDataPaths([], $jsonBody);

                $result = $this->jsonRepository->withUserLock($this->usersFilePath, function () use ($cookies, $constraintsPath, $historyPath, $year, $seed, $save): array {
                    $this->assertAdmin($cookies);
                    $constraints = $this->jsonRepository->loadConstraints($constraintsPath);
                    $history = $this->jsonRepository->loadHistory($historyPath);
                    $draw = $this->drawService->computeBestDraw($year, $constraints, $history, $seed);
                    if ($draw === null) {
                        throw new ApiException(422, 'no_solution', 'No valid perfect matching found for hard constraints');
                    }
                    if ($save) {
                        if (isset($history['years'][$year])) {
                            throw new ApiException(409, 'year_exists', "History already contains year {$year}");
                        }
                        $recipientToGiver = [];
                        foreach ($draw as $giver => $recipient) {
                            $recipientToGiver[$recipient] = (string) $giver;
                        }
                        $history['years'][$year] = $recipientToGiver;
                        $this->jsonRepository->saveHistory($historyPath, $history);
                    }
                    return ['year' => $year, 'saved' => $save, 'assignmentsByGiver' => array_is_list($draw) ? (object) $draw : $draw];
                });
                return $this->ok($result);
            }

            throw new ApiException(404, 'not_found', 'Route not found');
        } catch (ApiException $ex) {
            if ($ex->statusCode >= 500 || ($method === 'POST' && $path === '/password/forgot')) {
                $this->logServerError($method, $path, $ex->errorCode, $ex->getMessage());
            }
            return [
                'status' => $ex->statusCode,
                ...($path === '/wishlist' || str_starts_with($path, '/wishlist/')
                    ? ['headers' => ['Cache-Control' => 'no-store']] : []),
                'body' => [
                    'error' => [
                        'code' => $ex->errorCode,
                        'message' => $ex->getMessage(),
                    ],
                ],
            ];
        } catch (Throwable $ex) {
            $this->logServerError($method, $path, 'unhandled_exception', $ex->getMessage(), $ex);
            return [
                'status' => 500,
                ...($path === '/wishlist' || str_starts_with($path, '/wishlist/')
                    ? ['headers' => ['Cache-Control' => 'no-store']] : []),
                'body' => [
                    'error' => [
                        'code' => 'internal_error',
                        'message' => 'Unexpected server error',
                    ],
                ],
            ];
        }
    }

    /** @return array{status:int, body: array<string,mixed>} */
    private function ok(array $data): array
    {
        return [
            'status' => 200,
            'body' => ['data' => $data],
        ];
    }

    /** @return array{status:int, body: array<string,mixed>, headers: array<string,string>} */
    private function redirect(string $location): array
    {
        return [
            'status' => 302,
            'headers' => [
                'Location' => $location,
            ],
            'body' => [
                'data' => [
                    'redirectTo' => $location,
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $cookies
     * @return array{status:int, body: array<string,mixed>, cookies: array<int, array<string,mixed>>}
     */
    private function okWithCookies(array $data, array $cookies): array
    {
        $response = $this->ok($data);
        $response['cookies'] = $cookies;
        return $response;
    }

    /** @param array<string, string> $cookies @return array<string,mixed> */
    private function assertAuthenticated(array $cookies): array
    {
        $token = $cookies[$this->cookieName] ?? null;
        if (!is_string($token) || trim($token) === '') {
            throw new ApiException(401, 'missing_token', 'Missing authentication token');
        }

        return $this->authService->validateSessionToken(
            $token,
            time(),
            $this->jsonRepository->loadUsers($this->usersFilePath)
        );
    }

    /** @param array<string,string> $cookies */
    private function assertAdmin(array $cookies): void
    {
        if ($this->assertAuthenticated($cookies)['isAdmin'] !== true) {
            throw new ApiException(403, 'forbidden', 'Administrator access required');
        }
    }

    /** @return array<string,mixed> */
    private function buildSessionCookie(string $token, int $exp): array
    {
        return [
            'name' => $this->cookieName,
            'value' => $token,
            'expires' => $exp,
            'path' => $this->cookiePath,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    /** @param array<string,mixed> $source */
    private function readRequiredString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ApiException(400, 'invalid_input', "Field '{$key}' must be a non-empty string");
        }
        return $value;
    }

    /** @param array<string,mixed> $source */
    private function readRequiredInt(array $source, string $key): int
    {
        if (!array_key_exists($key, $source)) {
            throw new ApiException(400, 'invalid_input', "Missing required field '{$key}'");
        }
        return $this->toInt($source[$key], $key);
    }

    /** @param array<string,mixed> $source */
    private function readOptionalInt(array $source, string $key): ?int
    {
        if (!array_key_exists($key, $source)) {
            return null;
        }
        return $this->toInt($source[$key], $key);
    }

    /** @param array<string,mixed> $source */
    private function readOptionalBool(array $source, string $key): ?bool
    {
        if (!array_key_exists($key, $source)) {
            return null;
        }
        $value = $source[$key];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 'true' || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === 'false' || $value === 0 || $value === '0') {
            return false;
        }
        throw new ApiException(400, 'invalid_input', "Field '{$key}' must be a boolean");
    }

    /** @param array<string,mixed> $source */
    private function readYearFromQuery(array $source): int
    {
        if (!array_key_exists('year', $source)) {
            throw new ApiException(400, 'invalid_input', "Missing query parameter 'year'");
        }
        return $this->toInt($source['year'], 'year');
    }

    /** @param array<string,mixed> $query @param array<string,mixed>|null $body */
    private function readDataPaths(array $query, ?array $body): array
    {
        foreach ([$query, $body ?? []] as $source) {
            if (array_key_exists('constraintsFile', $source) || array_key_exists('historyFile', $source)) {
                throw new ApiException(400, 'invalid_input', 'Draw data file paths cannot be supplied by clients');
            }
        }
        return [$this->constraintsFilePath, $this->historyFilePath];
    }

    /** @param array<string,mixed> $source */
    private function rejectAuthFilePaths(array $source): void
    {
        if (array_key_exists('usersFile', $source) || array_key_exists('passwordResetsFile', $source)) {
            throw new ApiException(400, 'invalid_input', 'Authentication file paths cannot be supplied by clients');
        }
    }

    private function toInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $intValue = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $intValue = (int) $value;
        } else {
            throw new ApiException(400, 'invalid_input', "Field '{$field}' must be an integer");
        }

        if ($field === 'year' && ($intValue < 1900 || $intValue > 9999)) {
            throw new ApiException(400, 'invalid_input', "Field '{$field}' must be between 1900 and 9999");
        }

        return $intValue;
    }

    private function logServerError(string $method, string $path, string $errorCode, string $message, ?Throwable $exception = null): void
    {
        $logMessage = "[api_error] {$method} {$path} {$errorCode}: {$message}";
        if ($exception !== null) {
            $logMessage .= PHP_EOL . $exception->getTraceAsString();
        }
        error_log($logMessage);
    }
}
