<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;
use Noel\Api\Infrastructure\JsonRepository;

final class WishlistService
{
    private const MAX_IMAGE_BYTES = 5_000_000;
    private const MAX_IMAGES_BYTES = 20_000_000;
    private const MAX_TEXT_BYTES = 256 * 1024;

    public function __construct(
        private readonly JsonRepository $repository,
        private readonly DrawService $drawService,
        private readonly string $directory,
        private readonly string $constraintsPath,
        private readonly string $historyPath,
        private readonly string $usersPath,
        private readonly ?WishlistNotifier $notifier,
        private readonly string $frontendUrl
    ) {
    }

    /** @return array<string,mixed> */
    public function own(string $login): array
    {
        $participant = $this->participant($login);
        $document = $this->document(Identity::normalize($participant));
        $assignment = $this->assignment($participant);
        $giver = $assignment === null ? null : $this->findParticipant($assignment['giver']);
        return [
            'participant' => $participant,
            'ops' => $document['ops'],
            'revision' => $this->revision($document['ops']),
            'giver' => $giver === null ? null : [
                'name' => $giver,
                'key' => Identity::normalize($giver),
            ],
            'recipient' => $assignment === null ? null : [
                'name' => $assignment['recipient'],
                'key' => Identity::normalize($assignment['recipient']),
                'year' => $assignment['year'],
            ],
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function save(string $login, array $body): array
    {
        $participant = $this->participant($login);
        $identity = Identity::normalize($participant);
        $revision = $body['revision'] ?? null;
        $document = $this->document($identity);
        if (!is_string($revision) || !hash_equals($this->revision($document['ops']), $revision)) {
            throw new ApiException(409, 'wishlist_changed', 'Wishlist has changed since it was loaded');
        }
        $ops = $body['ops'] ?? null;
        $this->validateOps($ops);
        $this->repository->saveWishlist($this->directory, $identity, [
            'ops' => $ops, 'confirmedShares' => $this->activeConfirmations($document['confirmedShares'], time()),
        ]);
        return ['revision' => $this->revision($ops)];
    }

    /** @param array<string,mixed> $share @return array{revision:string,alreadyConfirmed:bool} */
    public function confirmShare(string $login, string $id, array $share, string $revision, int $expiresAt, int $now): array
    {
        $identity = Identity::normalize($this->participant($login));
        $document = $this->document($identity);
        $confirmed = $this->activeConfirmations($document['confirmedShares'], $now);
        $key = hash('sha256', $id);
        if (isset($confirmed[$key])) {
            return ['revision' => $this->revision($document['ops']), 'alreadyConfirmed' => true];
        }
        if (!hash_equals($this->revision($document['ops']), $revision)) {
            throw new ApiException(409, 'wishlist_changed', 'Wishlist has changed since it was loaded');
        }
        $ops = $document['ops'];
        $ops[] = ['insert' => "\n"];
        if ($share['title'] !== '') {
            $ops[] = ['insert' => $share['title'] . "\n"];
        }
        if ($share['url'] !== '') {
            $ops[] = ['insert' => $share['url'], 'attributes' => ['link' => $share['url']]];
            $ops[] = ['insert' => "\n"];
        }
        if ($share['text'] !== '') {
            $ops[] = ['insert' => $share['text'] . "\n"];
        }
        if ($share['image'] !== null) {
            $ops[] = ['insert' => ['image' => $share['image']]];
            $ops[] = ['insert' => "\n"];
        }
        $this->validateOps($ops);
        $confirmed[$key] = $expiresAt;
        $this->repository->saveWishlist($this->directory, $identity, ['ops' => $ops, 'confirmedShares' => $confirmed]);
        return ['revision' => $this->revision($ops), 'alreadyConfirmed' => false];
    }

    /** @return array{revision:string,alreadyConfirmed:bool}|null */
    public function confirmedShare(string $login, string $id, int $now): ?array
    {
        $identity = Identity::normalize($this->participant($login));
        $document = $this->document($identity);
        $confirmed = $this->activeConfirmations($document['confirmedShares'], $now);
        return isset($confirmed[hash('sha256', $id)])
            ? ['revision' => $this->revision($document['ops']), 'alreadyConfirmed' => true]
            : null;
    }

    /** @return array<string,mixed> */
    public function recipient(string $login, string $target): array
    {
        $participant = $this->participant($login);
        $assignment = $this->assignment($participant);
        if ($assignment === null || !hash_equals(Identity::normalize($assignment['recipient']), $target)) {
            throw new ApiException(403, 'wishlist_forbidden', 'Wishlist is not available for this draw');
        }
        $recipient = $this->participant($assignment['recipient']);
        return [
            'participant' => $recipient,
            'year' => $assignment['year'],
            'ops' => $this->document(Identity::normalize($recipient))['ops'],
        ];
    }

    public function notifyGiver(string $login, ?string $expectedGiver = null): string
    {
        $participant = $this->participant($login);
        $assignment = $this->assignment($participant);
        if ($expectedGiver !== null && ($assignment === null ||
            !hash_equals(Identity::normalize($assignment['giver']), $expectedGiver))) {
            throw new ApiException(409, 'giver_changed', 'Giver has changed since the wishlist was loaded');
        }
        if ($assignment === null) {
            throw new ApiException(404, 'draw_assignment_missing', 'No assignment in the latest draw');
        }
        $giver = $this->findParticipant($assignment['giver']);
        if ($giver === null && $expectedGiver !== null) {
            throw new ApiException(409, 'giver_changed', 'Giver is no longer available');
        }
        if ($giver === null) {
            throw new ApiException(403, 'participant_required', 'A participant account is required');
        }
        $user = null;
        foreach ($this->repository->loadUsers($this->usersPath)['users'] as $candidate) {
            if (Identity::normalize($candidate['login']) === Identity::normalize($giver)) {
                $user = $candidate;
                break;
            }
        }
        if ($user === null) {
            throw new ApiException(404, 'giver_account_missing', 'Giver has no account');
        }
        if ($this->notifier === null || !$this->safeUrl($this->frontendUrl, ['https'])
            || parse_url($this->frontendUrl, PHP_URL_QUERY) !== null
            || parse_url($this->frontendUrl, PHP_URL_FRAGMENT) !== null) {
            throw new ApiException(500, 'wishlist_config_missing', 'Wishlist notification is not configured');
        }
        $url = rtrim($this->frontendUrl, '/') . '/wishlist?target=' . rawurlencode(Identity::normalize($participant));
        $this->notifier->sendWishlistEmail($user['login'], $user['email'], $participant, $url);
        return $giver;
    }

    private function participant(string $identity): string
    {
        $participant = $this->findParticipant($identity);
        if ($participant !== null) {
            return $participant;
        }
        throw new ApiException(403, 'participant_required', 'A participant account is required');
    }

    private function findParticipant(string $identity): ?string
    {
        $normalized = Identity::normalize($identity);
        foreach ($this->repository->loadConstraints($this->constraintsPath)['users'] as $name) {
            if (Identity::normalize($name) === $normalized) {
                return $name;
            }
        }
        return null;
    }

    /** @return array{year:int,recipient:string,giver:string}|null */
    private function assignment(string $participant): ?array
    {
        $years = $this->repository->loadHistory($this->historyPath)['years'];
        $year = null;
        $currentYear = (int) date('Y');
        foreach ($years as $candidate => $_) {
            if ($candidate <= $currentYear && ($year === null || $candidate > $year)) {
                $year = $candidate;
            }
        }
        if ($year === null) {
            return null;
        }
        $draw = $years[$year];
        $this->drawService->validateHistoryYear($year, $draw);
        $key = Identity::normalize($participant);
        $recipient = null;
        $giver = null;
        foreach ($draw as $recipientName => $giverName) {
            if (Identity::normalize($giverName) === $key) {
                $recipient = (string) $recipientName;
            }
            if (Identity::normalize((string) $recipientName) === $key) {
                $giver = $giverName;
            }
        }
        return $recipient === null || $giver === null ? null : [
            'year' => $year, 'recipient' => $recipient, 'giver' => $giver,
        ];
    }

    /** @return array{ops:array,confirmedShares:array<string,int>} */
    private function document(string $identity): array
    {
        $document = $this->repository->loadWishlist($this->directory, $identity);
        if ($document === null) {
            return ['ops' => [['insert' => "\n"]], 'confirmedShares' => []];
        }
        $ops = $document['ops'] ?? null;
        $this->validateOps($ops);
        $confirmed = $document['confirmedShares'] ?? [];
        if (!is_array($confirmed)) {
            throw new ApiException(500, 'invalid_wishlist', 'Invalid share confirmations');
        }
        foreach ($confirmed as $key => $expiresAt) {
            if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || !is_int($expiresAt)) {
                throw new ApiException(500, 'invalid_wishlist', 'Invalid share confirmations');
            }
        }
        return ['ops' => $ops, 'confirmedShares' => $confirmed];
    }

    /** @param array<string,int> $confirmed @return array<string,int> */
    private function activeConfirmations(array $confirmed, int $now): array
    {
        return array_filter($confirmed, static fn (int $expiresAt): bool => $expiresAt > $now);
    }

    private function revision(array $ops): string
    {
        $encoded = json_encode($ops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new ApiException(500, 'serialization_error', 'Cannot encode wishlist');
        }
        return hash('sha256', $encoded);
    }

    private function validateOps(mixed $ops): void
    {
        if (!is_array($ops) || !array_is_list($ops) || count($ops) > 10000) {
            throw new ApiException(400, 'invalid_wishlist', 'Invalid wishlist document');
        }
        $imageBytes = 0;
        $textBytes = 0;
        foreach ($ops as $op) {
            if (!is_array($op) || array_diff(array_keys($op), ['insert', 'attributes']) !== []) {
                throw new ApiException(400, 'invalid_wishlist', 'Invalid wishlist operation');
            }
            $insert = $op['insert'] ?? null;
            if (is_string($insert)) {
                $textBytes += strlen($insert);
            } elseif (is_array($insert) && count($insert) === 1 && is_string($insert['image'] ?? null)) {
                $imageBytes += $this->validateImage($insert['image']);
            } else {
                throw new ApiException(400, 'invalid_wishlist', 'Unsupported wishlist content');
            }
            $attributes = $op['attributes'] ?? [];
            if (!is_array($attributes)) {
                throw new ApiException(400, 'invalid_wishlist', 'Invalid formatting');
            }
            foreach ($attributes as $name => $value) {
                $valid = match ($name) {
                    'bold', 'italic', 'underline', 'strike' => $value === true,
                    'header' => is_int($value) && $value >= 1 && $value <= 3,
                    'list' => in_array($value, ['ordered', 'bullet'], true),
                    'link' => is_string($value) && $this->safeUrl($value, ['https', 'http']),
                    default => false,
                };
                if (!$valid) {
                    throw new ApiException(400, 'invalid_wishlist', 'Unsupported formatting');
                }
            }
        }
        if ($imageBytes > self::MAX_IMAGES_BYTES || $textBytes > self::MAX_TEXT_BYTES) {
            throw new ApiException(413, 'wishlist_too_large', 'Wishlist exceeds its size limit');
        }
    }

    private function validateImage(string $image): int
    {
        if ($this->safeUrl($image, ['https'])) {
            return 0;
        }
        if (strlen($image) > (int) ceil(self::MAX_IMAGE_BYTES / 3) * 4 + 128) {
            throw new ApiException(413, 'image_too_large', 'Image exceeds 5 MB');
        }
        if (preg_match('#^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)$#D', $image, $matches) !== 1) {
            throw new ApiException(400, 'invalid_image', 'Invalid image');
        }
        $bytes = base64_decode($matches[2], true);
        if ($bytes === false || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new ApiException(413, 'image_too_large', 'Image exceeds 5 MB');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ($info['mime'] ?? '') !== 'image/' . $matches[1]
            || $info[0] > 10000 || $info[1] > 10000 || $info[0] * $info[1] > 40000000) {
            throw new ApiException(400, 'invalid_image', 'Invalid image content');
        }
        return strlen($bytes);
    }

    /** @param string[] $schemes */
    private function safeUrl(string $url, array $schemes): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), $schemes, true)
            && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }
}
