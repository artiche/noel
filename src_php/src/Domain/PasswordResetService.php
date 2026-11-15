<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;

final class PasswordResetService
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly PasswordResetNotifier $notifier,
        private readonly int $ttlSeconds,
        private readonly string $confirmationBaseUrl
    ) {
    }

    /**
     * @param array{users: array<int, array{login: string, passwordHash: string, email: string, isAdmin: bool, sessionVersion: int}>} $usersData
     * @param array{resets: array<int, array{id: string, login: string, newPasswordHash: string, expiresAt: int, usedAt: int|null, sessionVersion: int|null}>} $resetsData
     * @return array{
     *   resets: array<int, array{id: string, login: string, newPasswordHash: string, expiresAt: int, usedAt: int|null, sessionVersion: int|null}>,
     *   notification: array{login: string, email: string, temporaryPassword: string, confirmationUrl: string, expiresAt: int}
     * }
     */
    public function requestReset(string $login, array $usersData, array $resetsData, int $now): array
    {
        $userIndex = null;
        foreach ($usersData['users'] as $index => $user) {
            if (Identity::normalize($user['login']) === Identity::normalize($login)) {
                $userIndex = $index;
                break;
            }
        }

        if ($userIndex === null) {
            throw new ApiException(404, 'user_not_found', 'Unknown login');
        }

        $login = $usersData['users'][$userIndex]['login'];
        $email = $usersData['users'][$userIndex]['email'];
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'invalid_user_email', 'User email is invalid');
        }

        $temporaryPassword = $this->generateTemporaryPassword();
        $newPasswordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        if (!is_string($newPasswordHash) || $newPasswordHash === '') {
            throw new ApiException(500, 'password_hash_failed', 'Failed to hash temporary password');
        }

        $tokenId = bin2hex(random_bytes(16));
        $expiresAt = $now + $this->ttlSeconds;
        $token = $this->jwtService->createToken([
            'typ' => 'password_reset',
            'sub' => $login,
            'jti' => $tokenId,
            'iat' => $now,
            'exp' => $expiresAt,
        ]);

        foreach ($resetsData['resets'] as &$reset) {
            if ($reset['login'] === $login && $reset['usedAt'] === null) {
                $reset['usedAt'] = $now;
            }
        }
        unset($reset);
        $reset = [
            'id' => $tokenId,
            'login' => $login,
            'newPasswordHash' => $newPasswordHash,
            'expiresAt' => $expiresAt,
            'usedAt' => null,
            'sessionVersion' => $usersData['users'][$userIndex]['sessionVersion'],
        ];
        if (isset($usersData['users'][$userIndex]['accountId'])) {
            $reset['accountId'] = $usersData['users'][$userIndex]['accountId'];
        }
        $resetsData['resets'][] = $reset;

        return [
            'resets' => $resetsData['resets'],
            'notification' => [
                'login' => $login,
                'email' => $email,
                'temporaryPassword' => $temporaryPassword,
                'confirmationUrl' => $this->buildConfirmationUrl($token),
                'expiresAt' => $expiresAt,
            ],
        ];
    }

    /** @param array{login: string, email: string, temporaryPassword: string, confirmationUrl: string, expiresAt: int} $notification */
    public function sendNotification(array $notification): void
    {
        $this->notifier->sendPasswordResetEmail(
            $notification['login'],
            $notification['email'],
            $notification['temporaryPassword'],
            $notification['confirmationUrl'],
            $notification['expiresAt']
        );
    }

    /**
     * @param array{users: array<int, array{login: string, passwordHash: string, email: string, isAdmin: bool, sessionVersion: int}>} $usersData
     * @param array{resets: array<int, array{id: string, login: string, newPasswordHash: string, expiresAt: int, usedAt: int|null, sessionVersion: int|null}>} $resetsData
     * @return array{
     *   users: array<int, array{login: string, passwordHash: string, email: string, isAdmin: bool, sessionVersion: int}>,
     *   resets: array<int, array{id: string, login: string, newPasswordHash: string, expiresAt: int, usedAt: int|null, sessionVersion: int|null}>
     * }
     */
    public function confirmReset(string $token, array $usersData, array $resetsData, int $now): array
    {
        $claims = $this->jwtService->verifyToken($token, $now);
        if (($claims['typ'] ?? null) !== 'password_reset') {
            throw new ApiException(401, 'invalid_token', 'Invalid reset token type');
        }

        $tokenId = $claims['jti'] ?? null;
        $login = $claims['sub'] ?? null;
        if (!is_string($tokenId) || trim($tokenId) === '' || !is_string($login) || trim($login) === '') {
            throw new ApiException(401, 'invalid_token', 'Reset token is missing required claims');
        }

        $resetIndex = null;
        foreach ($resetsData['resets'] as $index => $resetEntry) {
            if ($resetEntry['id'] === $tokenId) {
                $resetIndex = $index;
                break;
            }
        }
        if ($resetIndex === null) {
            throw new ApiException(404, 'password_reset_not_found', 'Password reset request not found');
        }

        $resetEntry = $resetsData['resets'][$resetIndex];
        if ($resetEntry['login'] !== $login) {
            throw new ApiException(401, 'invalid_token', 'Reset token login mismatch');
        }
        if ($resetEntry['usedAt'] !== null) {
            throw new ApiException(409, 'password_reset_already_used', 'Password reset token already used');
        }
        if ($resetEntry['expiresAt'] <= $now) {
            throw new ApiException(401, 'token_expired', 'Password reset token has expired');
        }

        $userIndex = null;
        foreach ($usersData['users'] as $index => $user) {
            if ($user['login'] === $login) {
                $userIndex = $index;
                break;
            }
        }
        if ($userIndex === null) {
            throw new ApiException(404, 'user_not_found', 'Unknown login');
        }

        if ($resetEntry['sessionVersion'] === null
            || $resetEntry['sessionVersion'] !== $usersData['users'][$userIndex]['sessionVersion']
            || ($resetEntry['accountId'] ?? null) !== ($usersData['users'][$userIndex]['accountId'] ?? null)) {
            throw new ApiException(401, 'invalid_token', 'Password reset token has been revoked');
        }
        if ($usersData['users'][$userIndex]['sessionVersion'] === PHP_INT_MAX) {
            throw new ApiException(500, 'session_version_exhausted', 'Cannot increment session version');
        }

        $usersData['users'][$userIndex]['passwordHash'] = $resetEntry['newPasswordHash'];
        $usersData['users'][$userIndex]['sessionVersion']++;
        foreach ($resetsData['resets'] as &$reset) {
            if ($reset['login'] === $login && $reset['usedAt'] === null) {
                $reset['usedAt'] = $now;
            }
        }
        unset($reset);

        return [
            'users' => $usersData['users'],
            'resets' => $resetsData['resets'],
        ];
    }

    private function buildConfirmationUrl(string $token): string
    {
        $separator = str_contains($this->confirmationBaseUrl, '?') ? '&' : '?';
        return $this->confirmationBaseUrl . $separator . 'token=' . rawurlencode($token);
    }

    private function generateTemporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
        $length = strlen($alphabet);
        $password = '';
        for ($i = 0; $i < 14; $i++) {
            $password .= $alphabet[random_int(0, $length - 1)];
        }
        return $password;
    }
}

