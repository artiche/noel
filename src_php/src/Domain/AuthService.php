<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;

final class AuthService
{
    public function __construct(private readonly JwtService $jwtService)
    {
    }

    /**
     * @param array{users: array<int, array{login: string, passwordHash: string, email: string, isAdmin: bool, sessionVersion: int}>} $usersData
     * @return array{token: string, exp: int}
     */
    public function authenticate(string $login, string $password, array $usersData, int $now, int $ttlSeconds): array
    {
        foreach ($usersData['users'] as $user) {
            if ($user['login'] !== $login) {
                continue;
            }

            if (!password_verify($password, $user['passwordHash'])) {
                break;
            }

            $exp = $now + $ttlSeconds;
            $token = $this->jwtService->createToken([
                'sub' => $login,
                'isAdmin' => $user['isAdmin'],
                'sessionVersion' => $user['sessionVersion'],
                'iat' => $now,
                'exp' => $exp,
            ]);

            return ['token' => $token, 'exp' => $exp];
        }

        throw new ApiException(401, 'invalid_credentials', 'Invalid login or password');
    }

    /** @param array{users: array<int, array{login: string, passwordHash: string, email: string, isAdmin: bool, sessionVersion: int}>} $usersData @return array<string,mixed> */
    public function validateSessionToken(string $token, int $now, array $usersData): array
    {
        $claims = $this->jwtService->verifyToken($token, $now);
        if (!is_string($claims['sub'] ?? null) || trim($claims['sub']) === ''
            || !is_bool($claims['isAdmin'] ?? null) || !is_int($claims['sessionVersion'] ?? null)
            || $claims['sessionVersion'] < 0 || isset($claims['typ'])) {
            throw new ApiException(401, 'invalid_token', 'Invalid session token claims');
        }
        foreach ($usersData['users'] as $user) {
            if ($user['login'] === $claims['sub'] && $user['sessionVersion'] === $claims['sessionVersion']) {
                return $claims;
            }
        }
        throw new ApiException(401, 'invalid_token', 'Session has been revoked');
    }
}
