<?php

declare(strict_types=1);

namespace Noel\Api\Infrastructure;

use Noel\Api\Http\ApiException;

final class JsonRepository
{
    public function __construct(private readonly string $baseDirectory)
    {
    }

    /** @return array{users: string[], forbiddenRecipientsByGiver: array<string, string[]>} */
    public function loadConstraints(string $path): array
    {
        $fullPath = $this->resolvePath($path);
        $data = $this->decodeJsonFile($fullPath);

        $users = $data['users'] ?? null;
        $forbidden = $data['forbiddenRecipientsByGiver'] ?? null;
        if (!is_array($users) || !is_array($forbidden)) {
            throw new ApiException(422, 'invalid_constraints', "Invalid constraints structure in {$fullPath}");
        }

        $users = array_values(array_map(static fn ($u) => (string) $u, $users));
        if (count($users) === 0) {
            throw new ApiException(422, 'invalid_constraints', "No users found in {$fullPath}");
        }
        if (count($users) !== count(array_unique($users))) {
            throw new ApiException(422, 'invalid_constraints', "Duplicate users found in {$fullPath}");
        }

        $knownUsers = array_fill_keys($users, true);
        $normalizedForbidden = [];
        foreach ($users as $user) {
            $normalizedForbidden[$user] = [];
        }

        foreach ($forbidden as $giver => $recipients) {
            $giver = (string) $giver;
            if (!isset($knownUsers[$giver])) {
                throw new ApiException(422, 'invalid_constraints', "Unknown giver '{$giver}' in {$fullPath}");
            }
            if (!is_array($recipients)) {
                throw new ApiException(422, 'invalid_constraints', "Invalid forbidden recipients list for '{$giver}' in {$fullPath}");
            }

            $normalizedRecipients = [];
            foreach ($recipients as $recipient) {
                $recipient = (string) $recipient;
                if (!isset($knownUsers[$recipient])) {
                    throw new ApiException(422, 'invalid_constraints', "Unknown forbidden recipient '{$recipient}' for '{$giver}' in {$fullPath}");
                }
                $normalizedRecipients[] = $recipient;
            }
            $normalizedForbidden[$giver] = array_values(array_unique($normalizedRecipients));
        }

        return [
            'users' => $users,
            'forbiddenRecipientsByGiver' => $normalizedForbidden,
        ];
    }

    /** @return array{years: array<int, array<string,string>>} */
    public function loadHistory(string $path): array
    {
        $fullPath = $this->resolvePath($path);
        $data = $this->decodeJsonFile($fullPath);

        $yearsRaw = $data['years'] ?? $data['Years'] ?? null;
        if (!is_array($yearsRaw)) {
            throw new ApiException(422, 'invalid_history', "Invalid history structure in {$fullPath}");
        }

        $years = [];
        foreach ($yearsRaw as $year => $assignments) {
            if (!is_array($assignments)) {
                throw new ApiException(422, 'invalid_history', "Invalid year block '{$year}' in {$fullPath}");
            }

            $yearInt = filter_var($year, FILTER_VALIDATE_INT);
            if ($yearInt === false) {
                throw new ApiException(422, 'invalid_history', "Invalid year '{$year}' in {$fullPath}");
            }

            $normalizedAssignments = [];
            $seenGivers = [];
            foreach ($assignments as $recipient => $giver) {
                $recipient = (string) $recipient;
                $giver = (string) $giver;
                if (isset($seenGivers[$giver])) {
                    throw new ApiException(422, 'invalid_history', "Giver '{$giver}' appears multiple times in {$yearInt}");
                }
                $seenGivers[$giver] = true;
                $normalizedAssignments[$recipient] = $giver;
            }

            $years[(int) $yearInt] = $normalizedAssignments;
        }

        ksort($years);
        return ['years' => $years];
    }

    /** @return array{users: array<int, array{login: string, passwordHash: string, email: string, isAdmin: bool, sessionVersion: int}>} */
    public function loadUsers(string $path): array
    {
        $fullPath = $this->resolvePath($path);
        $data = $this->decodeJsonFile($fullPath);

        $usersRaw = $data['users'] ?? null;
        if (!is_array($usersRaw)) {
            throw new ApiException(422, 'invalid_users', "Invalid users structure in {$fullPath}");
        }

        $users = [];
        $seenLogins = [];
        foreach ($usersRaw as $entry) {
            if (!is_array($entry)) {
                throw new ApiException(422, 'invalid_users', "Invalid user entry in {$fullPath}");
            }

            $login = $entry['login'] ?? null;
            $passwordHash = $entry['passwordHash'] ?? null;
            $email = $entry['email'] ?? null;
            $isAdmin = array_key_exists('isAdmin', $entry) ? $entry['isAdmin'] : false;
            $sessionVersion = array_key_exists('sessionVersion', $entry) ? $entry['sessionVersion'] : 0;
            if (
                !is_string($login)
                || trim($login) === ''
                || !is_string($passwordHash)
                || trim($passwordHash) === ''
                || !is_string($email)
                || trim($email) === ''
            ) {
                throw new ApiException(422, 'invalid_users', "User entries must contain non-empty login, passwordHash and email in {$fullPath}");
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new ApiException(422, 'invalid_users', "User '{$login}' has an invalid email in {$fullPath}");
            }
            if (!is_bool($isAdmin)) {
                throw new ApiException(422, 'invalid_users', "User '{$login}' has an invalid isAdmin in {$fullPath}");
            }
            if (!is_int($sessionVersion) || $sessionVersion < 0) {
                throw new ApiException(422, 'invalid_users', "User '{$login}' has an invalid sessionVersion in {$fullPath}");
            }
            if (isset($seenLogins[$login])) {
                throw new ApiException(422, 'invalid_users', "Duplicate login '{$login}' in {$fullPath}");
            }
            $seenLogins[$login] = true;

            $users[] = [
                'login' => $login,
                'passwordHash' => $passwordHash,
                'email' => $email,
                'isAdmin' => $isAdmin,
                'sessionVersion' => $sessionVersion,
            ];
        }

        return ['users' => $users];
    }

    /** @param array{users: array<int, array{login: string, passwordHash: string, email: string, isAdmin: bool, sessionVersion: int}>} $users */
    public function saveUsers(string $path, array $users): void
    {
        $this->saveJson($path, ['users' => $users['users']]);
    }

    /** @return array{resets: array<int, array{id: string, login: string, newPasswordHash: string, expiresAt: int, usedAt: int|null, sessionVersion: int|null}>} */
    public function loadPasswordResets(string $path): array
    {
        $fullPath = $this->resolvePath($path);
        if (!is_file($fullPath)) {
            return ['resets' => []];
        }

        $data = $this->decodeJsonFile($fullPath);
        $resetsRaw = $data['resets'] ?? null;
        if (!is_array($resetsRaw)) {
            throw new ApiException(422, 'invalid_password_resets', "Invalid password resets structure in {$fullPath}");
        }

        $resets = [];
        foreach ($resetsRaw as $entry) {
            if (!is_array($entry)) {
                throw new ApiException(422, 'invalid_password_resets', "Invalid reset entry in {$fullPath}");
            }

            $id = $entry['id'] ?? null;
            $login = $entry['login'] ?? null;
            $newPasswordHash = $entry['newPasswordHash'] ?? null;
            $expiresAt = $entry['expiresAt'] ?? null;
            $usedAt = $entry['usedAt'] ?? null;
            $sessionVersion = $entry['sessionVersion'] ?? null;

            if (!is_string($id) || trim($id) === '' || !is_string($login) || trim($login) === '' || !is_string($newPasswordHash) || trim($newPasswordHash) === '') {
                throw new ApiException(422, 'invalid_password_resets', "Password reset entries must contain non-empty id, login and newPasswordHash in {$fullPath}");
            }
            if (!is_int($expiresAt) && !(is_string($expiresAt) && preg_match('/^\d+$/', $expiresAt) === 1)) {
                throw new ApiException(422, 'invalid_password_resets', "Password reset entry '{$id}' has invalid expiresAt in {$fullPath}");
            }
            if ($usedAt !== null && !is_int($usedAt) && !(is_string($usedAt) && preg_match('/^\d+$/', $usedAt) === 1)) {
                throw new ApiException(422, 'invalid_password_resets', "Password reset entry '{$id}' has invalid usedAt in {$fullPath}");
            }
            if ($sessionVersion !== null && (!is_int($sessionVersion) || $sessionVersion < 0)) {
                throw new ApiException(422, 'invalid_password_resets', "Password reset entry '{$id}' has invalid sessionVersion in {$fullPath}");
            }

            $resets[] = [
                'id' => $id,
                'login' => $login,
                'newPasswordHash' => $newPasswordHash,
                'expiresAt' => (int) $expiresAt,
                'usedAt' => $usedAt === null ? null : (int) $usedAt,
                'sessionVersion' => $sessionVersion,
            ];
        }

        return ['resets' => $resets];
    }

    /** @param array{resets: array<int, array{id: string, login: string, newPasswordHash: string, expiresAt: int, usedAt: int|null, sessionVersion: int|null}>} $resets */
    public function savePasswordResets(string $path, array $resets): void
    {
        $this->saveJson($path, ['resets' => $resets['resets']]);
    }

    public function withUserLock(string $usersPath, callable $operation): mixed
    {
        $lockPath = $this->resolvePath($usersPath) . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new ApiException(500, 'io_error', 'Cannot open user data lock');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new ApiException(500, 'io_error', 'Cannot acquire user data lock');
            }
            return $operation();
        } finally {
            fclose($lock);
        }
    }

    /** @param array{years: array<int, array<string,string>>} $history */
    public function saveHistory(string $path, array $history): void
    {
        $this->saveJson($path, ['years' => $history['years']]);
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->baseDirectory . DIRECTORY_SEPARATOR . $path;
    }

    /** @return array<string,mixed> */
    private function decodeJsonFile(string $fullPath): array
    {
        if (!is_file($fullPath)) {
            throw new ApiException(404, 'file_not_found', "File not found: {$fullPath}");
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new ApiException(500, 'io_error', "Failed to read file {$fullPath}");
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new ApiException(422, 'invalid_json', "Invalid JSON in {$fullPath}");
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function saveJson(string $path, array $payload): void
    {
        $fullPath = $this->resolvePath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new ApiException(500, 'io_error', "Cannot create directory '{$dir}'");
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new ApiException(500, 'serialization_error', 'Failed to encode JSON payload');
        }

        $tmp = $fullPath . '.tmp';
        if (file_put_contents($tmp, $encoded . PHP_EOL) === false) {
            throw new ApiException(500, 'io_error', "Failed to write temporary file '{$tmp}'");
        }
        if (!rename($tmp, $fullPath)) {
            @unlink($tmp);
            throw new ApiException(500, 'io_error', "Failed to replace file '{$fullPath}'");
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, '/');
    }
}
