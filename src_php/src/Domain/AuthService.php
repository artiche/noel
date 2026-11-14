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
     * @param array{users: array<int, array{login: string, passwordHash: string}>} $usersData
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
                'iat' => $now,
                'exp' => $exp,
            ]);

            return ['token' => $token, 'exp' => $exp];
        }

        throw new ApiException(401, 'invalid_credentials', 'Invalid login or password');
    }

    /** @return array<string,mixed> */
    public function validateSessionToken(string $token, int $now): array
    {
        return $this->jwtService->verifyToken($token, $now);
    }
}
