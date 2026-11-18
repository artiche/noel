<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;
use Noel\Api\Infrastructure\JsonRepository;

final class ShareTokenService
{
    public function __construct(private readonly JsonRepository $repository, private readonly string $path)
    {
    }

    /** @param array<string,mixed> $user @return array{id:string,token:string} */
    public function create(array $user): array
    {
        $data = $this->repository->loadShareTokens($this->path);
        $data['tokens'] = array_values(array_filter($data['tokens'], static fn ($token) => !is_array($token)
            || ($token['login'] ?? null) !== $user['login']
            || (($token['accountId'] ?? null) === ($user['accountId'] ?? null)
                && ($token['sessionVersion'] ?? null) === $user['sessionVersion'])));
        $current = array_filter($data['tokens'], static fn ($token) => is_array($token)
            && ($token['login'] ?? null) === $user['login']
            && ($token['accountId'] ?? null) === ($user['accountId'] ?? null)
            && ($token['sessionVersion'] ?? null) === $user['sessionVersion']);
        if (count($current) >= 10) {
            throw new ApiException(429, 'share_token_limit', 'Too many share tokens');
        }
        $token = bin2hex(random_bytes(32));
        $id = bin2hex(random_bytes(16));
        $data['tokens'][] = [
            'id' => $id,
            'hash' => hash('sha256', $token),
            'login' => $user['login'],
            'accountId' => $user['accountId'] ?? null,
            'sessionVersion' => $user['sessionVersion'],
            'createdAt' => time(),
        ];
        $this->repository->saveShareTokens($this->path, $data);
        return ['id' => $id, 'token' => $token];
    }

    /** @param array<string,mixed> $user @return array<int,array{id:string,createdAt:int|null}> */
    public function list(array $user): array
    {
        $result = [];
        foreach ($this->repository->loadShareTokens($this->path)['tokens'] as $entry) {
            if ($entry['login'] === $user['login']
                && $entry['accountId'] === ($user['accountId'] ?? null)
                && $entry['sessionVersion'] === $user['sessionVersion']) {
                $result[] = ['id' => $entry['id'], 'createdAt' => $entry['createdAt'] ?? null];
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $user */
    public function revoke(array $user, string $id): void
    {
        $data = $this->repository->loadShareTokens($this->path);
        $before = count($data['tokens']);
        $data['tokens'] = array_values(array_filter($data['tokens'], static fn ($token) => !is_array($token)
            || ($token['id'] ?? null) !== $id || ($token['login'] ?? null) !== $user['login']
            || ($token['accountId'] ?? null) !== ($user['accountId'] ?? null)));
        if (count($data['tokens']) === $before) {
            throw new ApiException(404, 'share_token_not_found', 'Share token not found');
        }
        $this->repository->saveShareTokens($this->path, $data);
    }

    /** @param array<int,array<string,mixed>> $users @return array<string,mixed> */
    public function authenticate(string $token, array $users): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new ApiException(401, 'invalid_share_token', 'Invalid share token');
        }
        $hash = hash('sha256', $token);
        foreach ($this->repository->loadShareTokens($this->path)['tokens'] as $entry) {
            if (!is_array($entry) || !is_string($entry['hash'] ?? null)
                || !hash_equals($entry['hash'], $hash)) {
                continue;
            }
            foreach ($users as $user) {
                if ($user['login'] === ($entry['login'] ?? null)
                    && ($user['accountId'] ?? null) === ($entry['accountId'] ?? null)
                    && $user['sessionVersion'] === ($entry['sessionVersion'] ?? null)) {
                    return $user;
                }
            }
        }
        throw new ApiException(401, 'invalid_share_token', 'Invalid share token');
    }
}
