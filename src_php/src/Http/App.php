<?php

declare(strict_types=1);

namespace Noel\Api\Http;

use Noel\Api\Domain\AuthService;
use Noel\Api\Domain\DrawService;
use Noel\Api\Infrastructure\JsonRepository;

final class App
{
    public function __construct(
        private readonly JsonRepository $jsonRepository,
        private readonly DrawService $drawService,
        private readonly AuthService $authService,
        private readonly string $cookieName,
        private readonly int $jwtTtlSeconds,
        private readonly string $cookiePath
    ) {
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, string> $cookies
     * @return array{status: int, body: array<string,mixed>, cookies?: array<int, array<string,mixed>>}
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
                $usersPath = $this->readUsersPath([], $jsonBody);

                $usersData = $this->jsonRepository->loadUsers($usersPath);
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

            $this->assertAuthenticated($cookies);

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
            return [
                'status' => $ex->statusCode,
                'body' => [
                    'error' => [
                        'code' => $ex->errorCode,
                        'message' => $ex->getMessage(),
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

    /** @param array<string, string> $cookies */
    private function assertAuthenticated(array $cookies): void
    {
        $token = $cookies[$this->cookieName] ?? null;
        if (!is_string($token) || trim($token) === '') {
            throw new ApiException(401, 'missing_token', 'Missing authentication token');
        }

        $this->authService->validateSessionToken($token, time());
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
        $constraintsPath = $query['constraintsFile'] ?? $body['constraintsFile'] ?? 'data/constraints.json';
        $historyPath = $query['historyFile'] ?? $body['historyFile'] ?? 'data/history.json';

        if (!is_string($constraintsPath) || !is_string($historyPath)) {
            throw new ApiException(400, 'invalid_input', 'constraintsFile and historyFile must be strings');
        }

        return [$constraintsPath, $historyPath];
    }

    /** @param array<string,mixed> $query @param array<string,mixed>|null $body */
    private function readUsersPath(array $query, ?array $body): string
    {
        $usersPath = $query['usersFile'] ?? $body['usersFile'] ?? 'data/users.json';
        if (!is_string($usersPath) || trim($usersPath) === '') {
            throw new ApiException(400, 'invalid_input', 'usersFile must be a non-empty string');
        }
        return $usersPath;
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
}
