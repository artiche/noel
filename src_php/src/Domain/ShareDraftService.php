<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;
use Noel\Api\Infrastructure\ShareDraftStore;

final class ShareDraftService
{
    private const TTL = 7 * 24 * 60 * 60;

    public function __construct(private readonly ShareDraftStore $store)
    {
    }

    /** @param array<string,mixed> $fields @param array<string,mixed>|null $owner @return array<string,mixed> */
    public function create(array $fields, ?string $image, string $source, ?array $owner, int $now): array
    {
        return $this->store->locked(function () use ($fields, $image, $source, $owner, $now): array {
            $this->store->cleanup($now);
            $content = $this->normalize($fields, $image);
            $id = bin2hex(random_bytes(32));
            $expiresAt = $now + self::TTL;
            $this->store->create($id, $content + [
                'source' => $source,
                'owner' => $owner,
                'expiresAt' => $expiresAt,
            ]);
            return ['draftId' => $id, 'expiresAt' => $expiresAt];
        });
    }

    public function limit(string $address, int $now): void
    {
        $this->store->locked(fn () => $this->store->limit($address, $now));
    }

    /** @param array<string,mixed> $fields @return array{title:string,url:string,text:string,image:?string} */
    public function normalize(array $fields, ?string $image): array
    {
        if (array_diff(array_keys($fields), ['title', 'url', 'text']) !== []) {
            throw new ApiException(400, 'invalid_share', 'Unexpected share field');
        }
        $values = [];
        foreach (['title' => 4096, 'url' => 8192, 'text' => 65536] as $name => $limit) {
            $value = array_key_exists($name, $fields) ? $fields[$name] : '';
            if (!is_string($value) || strlen($value) > $limit || !mb_check_encoding($value, 'UTF-8')
                || str_contains($value, "\0")) {
                throw new ApiException(400, 'invalid_share', "Invalid share field: {$name}");
            }
            $values[$name] = trim($value);
        }
        if ($values['url'] === '' && preg_match('~https?://[^\s<>]+~iu', $values['text'], $match, PREG_OFFSET_CAPTURE) === 1) {
            $candidate = rtrim($match[0][0], '.,;:!?)]}');
            if ($this->safeUrl($candidate)) {
                $values['url'] = $candidate;
                $offset = $match[0][1];
                $values['text'] = trim(substr($values['text'], 0, $offset)
                    . substr($values['text'], $offset + strlen($candidate)));
            }
        }
        if ($values['url'] !== '' && !$this->safeUrl($values['url'])) {
            throw new ApiException(400, 'invalid_share_url', 'Invalid share URL');
        }
        if ($image !== null) {
            $this->validateImage($image);
        }
        if ($values['url'] === '' && $values['text'] === '' && $image === null) {
            throw new ApiException(400, 'empty_share', 'Share must contain a URL, text or image');
        }
        return $values + ['image' => $image];
    }

    public function imageFromBytes(string $bytes): string
    {
        if (strlen($bytes) > 5_000_000) {
            throw new ApiException(413, 'image_too_large', 'Image exceeds 5 MB');
        }
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)
            || $info[0] > 10000 || $info[1] > 10000 || $info[0] * $info[1] > 40000000) {
            throw new ApiException(400, 'invalid_image', 'Invalid image content');
        }
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /** @param array<string,mixed> $owner @return array<string,mixed> */
    public function open(string $id, array $owner, int $now): array
    {
        return $this->withClaimed($id, $owner, $now, static fn (array $draft): array => [
            'title' => $draft['title'], 'url' => $draft['url'], 'text' => $draft['text'],
            'image' => $draft['image'], 'source' => $draft['source'], 'expiresAt' => $draft['expiresAt'],
        ]);
    }

    /** @param array<string,mixed> $owner */
    public function cancel(string $id, array $owner, int $now): void
    {
        $this->withClaimed($id, $owner, $now, function () use ($id): void {
            $this->store->delete($id);
        });
    }

    /** @param array<string,mixed> $owner */
    public function withClaimed(string $id, array $owner, int $now, callable $operation): mixed
    {
        return $this->store->locked(function () use ($id, $owner, $now, $operation): mixed {
            $draft = $this->store->read($id);
            if ($draft === null) {
                throw new ApiException(404, 'share_not_found', 'Share draft not found');
            }
            if (!is_int($draft['expiresAt'] ?? null)) {
                throw new ApiException(500, 'share_storage_error', 'Invalid share draft');
            }
            if ($draft['expiresAt'] <= $now) {
                throw new ApiException(410, 'share_expired', 'Share draft has expired');
            }
            if (($draft['owner'] ?? null) === null) {
                $draft['owner'] = $owner;
                $this->store->save($id, $draft);
            } elseif ($draft['owner'] !== $owner) {
                throw new ApiException(403, 'share_forbidden', 'Share draft belongs to another account');
            }
            return $operation($draft);
        });
    }

    public function delete(string $id): void
    {
        $this->store->delete($id);
    }

    /** @param array<string,mixed> $owner */
    public function discardConfirmed(string $id, array $owner): void
    {
        $this->store->locked(function () use ($id, $owner): void {
            $draft = $this->store->read($id);
            if ($draft === null) {
                return;
            }
            if (($draft['owner'] ?? null) !== $owner) {
                throw new ApiException(403, 'share_forbidden', 'Share draft belongs to another account');
            }
            $this->store->delete($id);
        });
    }

    public function cleanup(int $now): int
    {
        return $this->store->locked(fn (): int => $this->store->cleanup($now, PHP_INT_MAX));
    }

    private function validateImage(string $image): void
    {
        if (strlen($image) > (int) ceil(5_000_000 / 3) * 4 + 128
            || preg_match('#^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)$#D', $image, $matches) !== 1) {
            throw new ApiException(400, 'invalid_image', 'Invalid image');
        }
        $bytes = base64_decode($matches[2], true);
        if ($bytes === false || $this->imageFromBytes($bytes) !== $image) {
            throw new ApiException(400, 'invalid_image', 'Invalid image content');
        }
    }

    private function safeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }
}
