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

    /** @return array{users: array<int, array{login: string, passwordHash: string}>} */
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
            if (!is_string($login) || trim($login) === '' || !is_string($passwordHash) || trim($passwordHash) === '') {
                throw new ApiException(422, 'invalid_users', "User entries must contain non-empty login and passwordHash in {$fullPath}");
            }
            if (isset($seenLogins[$login])) {
                throw new ApiException(422, 'invalid_users', "Duplicate login '{$login}' in {$fullPath}");
            }
            $seenLogins[$login] = true;

            $users[] = [
                'login' => $login,
                'passwordHash' => $passwordHash,
            ];
        }

        return ['users' => $users];
    }

    /** @param array{years: array<int, array<string,string>>} $history */
    public function saveHistory(string $path, array $history): void
    {
        $fullPath = $this->resolvePath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new ApiException(500, 'io_error', "Cannot create directory '{$dir}'");
        }

        $encoded = json_encode(['years' => $history['years']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new ApiException(500, 'serialization_error', 'Failed to encode history JSON');
        }

        $tmp = $fullPath . '.tmp';
        if (file_put_contents($tmp, $encoded . PHP_EOL) === false) {
            throw new ApiException(500, 'io_error', "Failed to write temporary history file '{$tmp}'");
        }
        if (!rename($tmp, $fullPath)) {
            @unlink($tmp);
            throw new ApiException(500, 'io_error', "Failed to replace history file '{$fullPath}'");
        }
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

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, '/');
    }
}
