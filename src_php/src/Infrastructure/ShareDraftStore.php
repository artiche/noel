<?php

declare(strict_types=1);

namespace Noel\Api\Infrastructure;

use Noel\Api\Http\ApiException;

final class ShareDraftStore
{
    private const MAX_FILES = 128;
    private const MAX_BYTES = 128_000_000;
    private const RATE_LIMIT = 20;
    private const RATE_WINDOW = 3600;

    public function __construct(private readonly string $directory)
    {
    }

    public function locked(callable $operation): mixed
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new ApiException(500, 'share_storage_error', 'Cannot create share storage');
        }
        $lock = fopen($this->directory . '/.lock', 'c');
        if ($lock === false) {
            throw new ApiException(500, 'share_storage_error', 'Cannot open share storage lock');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new ApiException(500, 'share_storage_error', 'Cannot acquire share storage lock');
            }
            return $operation();
        } finally {
            fclose($lock);
        }
    }

    public function limit(string $address, int $now): void
    {
        $path = $this->directory . '/rate.json';
        $rates = is_file($path) ? $this->readJson($path) : [];
        foreach ($rates as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || !is_array($value)
                || !is_int($value['start'] ?? null) || !is_int($value['count'] ?? null)
                || $value['count'] < 0) {
                throw new ApiException(500, 'share_storage_error', 'Invalid share rate storage');
            }
        }
        $rates = array_filter($rates, static fn (array $value): bool => $value['start'] > $now - self::RATE_WINDOW);
        $key = hash('sha256', $address);
        if (!isset($rates[$key]) && count($rates) >= 4096) {
            throw new ApiException(429, 'share_rate_limit', 'Share rate limit reached');
        }
        $entry = $rates[$key] ?? ['start' => $now, 'count' => 0];
        if ($entry['count'] >= self::RATE_LIMIT) {
            throw new ApiException(429, 'share_rate_limit', 'Share rate limit reached');
        }
        $entry['count']++;
        $rates[$key] = $entry;
        $this->writeJson($path, $rates);
    }

    /** @param array<string,mixed> $draft */
    public function create(string $id, array $draft): void
    {
        $files = glob($this->directory . '/draft-*.json*');
        if ($files === false) {
            throw new ApiException(500, 'share_storage_error', 'Cannot enumerate share storage');
        }
        $encoded = $this->encode($draft);
        $size = strlen($encoded);
        foreach ($files as $file) {
            $fileSize = filesize($file);
            if ($fileSize === false) {
                throw new ApiException(500, 'share_storage_error', 'Cannot inspect share storage');
            }
            $size += $fileSize;
        }
        if (count($files) >= self::MAX_FILES || $size > self::MAX_BYTES) {
            throw new ApiException(429, 'share_storage_full', 'Share storage quota reached');
        }
        $path = $this->path($id);
        if (file_exists($path)) {
            throw new ApiException(500, 'share_storage_error', 'Share identifier collision');
        }
        $this->writeEncoded($path, $encoded);
    }

    /** @return array<string,mixed>|null */
    public function read(string $id): ?array
    {
        $path = $this->path($id);
        return is_file($path) ? $this->readJson($path) : null;
    }

    /** @param array<string,mixed> $draft */
    public function save(string $id, array $draft): void
    {
        $this->writeJson($this->path($id), $draft);
    }

    public function delete(string $id): void
    {
        $path = $this->path($id);
        if (is_file($path) && !unlink($path)) {
            throw new ApiException(500, 'share_storage_error', 'Cannot delete share draft');
        }
    }

    public function cleanup(int $now, int $limit = 10): int
    {
        $files = glob($this->directory . '/draft-*.json*');
        if ($files === false) {
            throw new ApiException(500, 'share_storage_error', 'Cannot enumerate share storage');
        }
        $removed = 0;
        foreach ($files as $file) {
            if ($removed >= $limit) {
                break;
            }
            if (str_ends_with($file, '.tmp')) {
                $modified = filemtime($file);
                $expired = $modified !== false && $modified <= $now - self::RATE_WINDOW;
            } else {
                $draft = $this->readJson($file);
                $expiresAt = $draft['expiresAt'] ?? null;
                if (!is_int($expiresAt)) {
                    throw new ApiException(500, 'share_storage_error', 'Invalid share draft');
                }
                $expired = $expiresAt <= $now;
            }
            if ($expired) {
                if (!unlink($file)) {
                    throw new ApiException(500, 'share_storage_error', 'Cannot clean share storage');
                }
                $removed++;
            }
        }
        return $removed;
    }

    private function path(string $id): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
            throw new ApiException(400, 'invalid_share_id', 'Invalid share identifier');
        }
        return $this->directory . '/draft-' . hash('sha256', $id) . '.json';
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new ApiException(500, 'share_storage_error', 'Cannot read share storage');
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new ApiException(500, 'share_storage_error', 'Invalid share storage');
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function encode(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new ApiException(500, 'serialization_error', 'Cannot encode share storage');
        }
        return $encoded;
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->writeEncoded($path, $this->encode($data));
    }

    private function writeEncoded(string $path, string $encoded): void
    {
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, $encoded) !== strlen($encoded)) {
            if (is_file($temporary) && !unlink($temporary)) {
                error_log('[share_storage_error] Cannot delete incomplete share file');
            }
            throw new ApiException(500, 'share_storage_error', 'Cannot write share storage');
        }
        if (!rename($temporary, $path)) {
            if (is_file($temporary) && !unlink($temporary)) {
                error_log('[share_storage_error] Cannot delete incomplete share file');
            }
            throw new ApiException(500, 'share_storage_error', 'Cannot replace share storage');
        }
    }
}
