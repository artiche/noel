<?php

declare(strict_types=1);

namespace Noel\Api\Http;

use Throwable;
use Noel\Api\Domain\AuthService;
use Noel\Api\Domain\DrawService;
use Noel\Api\Domain\PasswordResetService;
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
        private readonly string $loginRedirectUrl = '/login'
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
                $response = $this->ok(['authenticated' => true, 'isAdmin' => $session['isAdmin']]);
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
                    'possibleRecipientsByGiver' => $possible,
                ]);
            }

            if ($method === 'GET' && preg_match('#^/draw/(\d{4})$#', $path, $matches) === 1) {
                $year = (int) $matches[1];
                [$constraintsPath, $historyPath] = $this->readDataPaths($query, null);

                $constraints = $this->jsonRepository->loadConstraints($constraintsPath);
                $history = $this->jsonRepository->loadHistory($historyPath);

                $yearDraw = $history['years'][$year] ?? null;
                if ($yearDraw === null) {
                    throw new ApiException(404, 'draw_not_found', "No draw found for year {$year}");
                }

                $giverToRecipient = [];
                foreach ($yearDraw as $recipient => $giver) {
                    $giverToRecipient[$giver] = $recipient;
                }
                ksort($giverToRecipient, SORT_STRING);

                $lines = [];
                foreach ($constraints['users'] as $giver) {
                    if (!isset($giverToRecipient[$giver])) {
                        throw new ApiException(422, 'invalid_history', "Missing giver '{$giver}' in history year {$year}");
                    }
                    $lines[] = "{$giver} offre un cadeau à {$giverToRecipient[$giver]}";
                }

                return $this->ok([
                    'year' => $year,
                    'assignmentsByGiver' => $giverToRecipient,
                    'lines' => $lines,
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

                $constraints = $this->jsonRepository->loadConstraints($constraintsPath);
                $history = $this->jsonRepository->loadHistory($historyPath);

                $draw = $this->drawService->computeBestDraw($year, $constraints, $history, $seed);
                if ($draw === null) {
                    throw new ApiException(422, 'no_solution', 'No valid perfect matching found for hard constraints');
                }

                $saved = false;
                if ($save) {
                    if (isset($history['years'][$year])) {
                        throw new ApiException(409, 'year_exists', "History already contains year {$year}");
                    }
                    $recipientToGiver = [];
                    foreach ($draw as $giver => $recipient) {
                        $recipientToGiver[$recipient] = $giver;
                    }
                    $history['years'][$year] = $recipientToGiver;
                    $this->jsonRepository->saveHistory($historyPath, $history);
                    $saved = true;
                }

                return $this->ok([
                    'year' => $year,
                    'saved' => $saved,
                    'assignmentsByGiver' => $draw,
                ]);
            }

            throw new ApiException(404, 'not_found', 'Route not found');
        } catch (ApiException $ex) {
            if ($ex->statusCode >= 500 || ($method === 'POST' && $path === '/password/forgot')) {
                $this->logServerError($method, $path, $ex->errorCode, $ex->getMessage());
            }
            return [
                'status' => $ex->statusCode,
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
        return ['data/constraints.json', 'data/history.json'];
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
