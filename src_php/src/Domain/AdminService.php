<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;
use Noel\Api\Infrastructure\JsonRepository;

final class AdminService
{
    public function __construct(
        private readonly JsonRepository $repository,
        private readonly string $usersPath,
        private readonly string $resetsPath,
        private readonly string $constraintsPath,
        private readonly ?string $wishlistDirectory = null
    ) {
    }

    /** @return array<string,mixed> */
    public function list(): array
    {
        $users = $this->repository->loadUsers($this->usersPath)['users'];
        $constraints = $this->repository->loadConstraints($this->constraintsPath);
        $accounts = [];
        $byIdentity = [];
        foreach ($users as $user) {
            $accounts[] = ['login' => $user['login'], 'email' => $user['email'], 'isAdmin' => $user['isAdmin']];
            $byIdentity[Identity::normalize($user['login'])] = $user['login'];
        }
        $participants = [];
        foreach ($constraints['users'] as $name) {
            $identity = Identity::normalize($name);
            $participants[] = [
                'name' => $name,
                'login' => $byIdentity[$identity] ?? null,
                'suggestedLogin' => $identity,
                'forbiddenRecipients' => $constraints['forbiddenRecipientsByGiver'][$name],
            ];
        }
        return ['users' => $accounts, 'participants' => $participants];
    }

    /** @param array<string,mixed> $body */
    public function createUser(array $body): void
    {
        $login = $this->string($body, 'login');
        $email = $this->email($body);
        $isAdmin = $this->boolean($body, 'isAdmin');
        $users = $this->repository->loadUsers($this->usersPath);
        foreach ($users['users'] as $user) {
            if (Identity::normalize($user['login']) === Identity::normalize($login)) {
                throw new ApiException(409, 'login_exists', 'Login already exists');
            }
        }
        if (array_key_exists('participant', $body)) {
            $participant = $this->findParticipant($this->repository->loadConstraints($this->constraintsPath), $this->string($body, 'participant'));
            if (Identity::normalize($participant) !== Identity::normalize($login)) {
                throw new ApiException(400, 'identity_mismatch', 'Login must match participant name');
            }
        }
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new ApiException(500, 'password_hash_failed', 'Failed to hash password');
        }
        $users['users'][] = [
            'login' => $login, 'email' => $email, 'isAdmin' => $isAdmin,
            'passwordHash' => $hash, 'sessionVersion' => 0, 'accountId' => bin2hex(random_bytes(16)),
        ];
        $this->repository->saveUsers($this->usersPath, $users);
    }

    /** @param array<string,mixed> $body */
    public function updateUser(array $body): void
    {
        $login = $this->string($body, 'login');
        $users = $this->repository->loadUsers($this->usersPath);
        $index = $this->findUser($users['users'], $login);
        $user = $users['users'][$index];
        if (!array_key_exists('email', $body) && !array_key_exists('isAdmin', $body) && !array_key_exists('password', $body)) {
            throw new ApiException(400, 'invalid_input', 'No changes requested');
        }
        $email = array_key_exists('email', $body) ? $this->email($body) : $user['email'];
        $isAdmin = array_key_exists('isAdmin', $body) ? $this->boolean($body, 'isAdmin') : $user['isAdmin'];
        if ($user['isAdmin'] && !$isAdmin) {
            $this->assertAnotherAdmin($users['users'], $index);
        }
        $passwordChanged = array_key_exists('password', $body);
        if ($passwordChanged) {
            $password = $this->string($body, 'password', false);
            if (strlen($password) > 72 || str_contains($password, "\0")) {
                throw new ApiException(400, 'invalid_input', 'Password must not contain NUL or exceed 72 bytes');
            }
            if ($user['sessionVersion'] === PHP_INT_MAX) {
                throw new ApiException(500, 'session_version_exhausted', 'Cannot increment session version');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($hash) || $hash === '') {
                throw new ApiException(500, 'password_hash_failed', 'Failed to hash password');
            }
            $user['passwordHash'] = $hash;
            $user['sessionVersion']++;
        }
        if ($passwordChanged || $email !== $user['email']) {
            $this->invalidateResets($user['login']);
        }
        $user['email'] = $email;
        $user['isAdmin'] = $isAdmin;
        $users['users'][$index] = $user;
        $this->repository->saveUsers($this->usersPath, $users);
    }

    public function deleteUser(string $login): void
    {
        $users = $this->repository->loadUsers($this->usersPath);
        $index = $this->findUser($users['users'], $login);
        if ($users['users'][$index]['isAdmin']) {
            $this->assertAnotherAdmin($users['users'], $index);
        }
        $this->invalidateResets($users['users'][$index]['login']);
        array_splice($users['users'], $index, 1);
        $this->repository->saveUsers($this->usersPath, $users);
    }

    public function createParticipant(string $name): void
    {
        $name = $this->validName($name);
        $constraints = $this->repository->loadConstraints($this->constraintsPath);
        foreach ($constraints['users'] as $existing) {
            if (Identity::normalize($existing) === Identity::normalize($name)) {
                throw new ApiException(409, 'participant_exists', 'Participant already exists');
            }
        }
        if ($this->wishlistDirectory !== null) {
            $this->repository->deleteWishlist($this->wishlistDirectory, Identity::normalize($name));
        }
        $constraints['users'][] = $name;
        $constraints['forbiddenRecipientsByGiver'][$name] = [];
        $this->repository->saveConstraints($this->constraintsPath, $constraints);
    }

    /** @param array<string,mixed> $body */
    public function updateParticipant(array $body): void
    {
        $constraints = $this->repository->loadConstraints($this->constraintsPath);
        $name = $this->findParticipant($constraints, $this->string($body, 'name'));
        if (!array_key_exists('newName', $body) && !array_key_exists('forbiddenRecipients', $body)) {
            throw new ApiException(400, 'invalid_input', 'No changes requested');
        }
        $newName = array_key_exists('newName', $body) ? $this->validName($this->string($body, 'newName')) : $name;
        if (Identity::normalize($name) !== Identity::normalize($newName)) {
            throw new ApiException(400, 'identity_mismatch', 'New name must match the existing participant');
        }
        if (array_key_exists('forbiddenRecipients', $body)) {
            if (!is_array($body['forbiddenRecipients']) || !array_is_list($body['forbiddenRecipients'])) {
                throw new ApiException(400, 'invalid_input', 'Invalid forbidden recipients');
            }
            $recipients = [];
            foreach ($body['forbiddenRecipients'] as $recipient) {
                if (!is_string($recipient) || trim($recipient) === '') {
                    throw new ApiException(400, 'invalid_input', 'Invalid forbidden recipient');
                }
                $canonical = $this->findParticipant($constraints, $recipient);
                if ($canonical === $name) {
                    throw new ApiException(400, 'invalid_input', 'A participant cannot forbid themselves');
                }
                if (!in_array($canonical, $recipients, true)) {
                    $recipients[] = $canonical;
                }
            }
            $constraints['forbiddenRecipientsByGiver'][$name] = $recipients;
        }
        if ($newName !== $name) {
            foreach ($constraints['users'] as &$participant) {
                if ($participant === $name) {
                    $participant = $newName;
                }
            }
            unset($participant);
            $forbidden = $constraints['forbiddenRecipientsByGiver'];
            $own = $forbidden[$name];
            unset($forbidden[$name]);
            $forbidden[$newName] = $own;
            foreach ($forbidden as &$recipients) {
                foreach ($recipients as &$recipient) {
                    if ($recipient === $name) {
                        $recipient = $newName;
                    }
                }
                unset($recipient);
            }
            unset($recipients);
            $constraints['forbiddenRecipientsByGiver'] = $forbidden;
        }
        $this->repository->saveConstraints($this->constraintsPath, $constraints);
    }

    public function deleteParticipant(string $name): void
    {
        $constraints = $this->repository->loadConstraints($this->constraintsPath);
        $original = $constraints;
        $name = $this->findParticipant($constraints, $name);
        $constraints['users'] = array_values(array_filter($constraints['users'], static fn (string $item): bool => $item !== $name));
        unset($constraints['forbiddenRecipientsByGiver'][$name]);
        foreach ($constraints['forbiddenRecipientsByGiver'] as &$recipients) {
            $recipients = array_values(array_filter($recipients, static fn (string $item): bool => $item !== $name));
        }
        unset($recipients);
        $this->repository->saveConstraints($this->constraintsPath, $constraints);
        if ($this->wishlistDirectory !== null) {
            try {
                $this->repository->deleteWishlist($this->wishlistDirectory, Identity::normalize($name));
            } catch (\Throwable $exception) {
                $this->repository->saveConstraints($this->constraintsPath, $original);
                throw $exception;
            }
        }
    }

    /** @param array<int,array<string,mixed>> $users */
    private function findUser(array $users, string $login): int
    {
        foreach ($users as $index => $user) {
            if (Identity::normalize($user['login']) === Identity::normalize($login)) {
                return $index;
            }
        }
        throw new ApiException(404, 'user_not_found', 'Unknown login');
    }

    /** @param array<int,array<string,mixed>> $users */
    private function assertAnotherAdmin(array $users, int $excluded): void
    {
        foreach ($users as $index => $user) {
            if ($index !== $excluded && $user['isAdmin'] === true) {
                return;
            }
        }
        throw new ApiException(409, 'last_admin', 'The last administrator cannot be removed');
    }

    private function invalidateResets(string $login): void
    {
        $resets = $this->repository->loadPasswordResets($this->resetsPath);
        foreach ($resets['resets'] as &$reset) {
            if ($reset['login'] === $login && $reset['usedAt'] === null) {
                $reset['usedAt'] = time();
            }
        }
        unset($reset);
        $this->repository->savePasswordResets($this->resetsPath, $resets);
    }

    /** @param array{users:string[]} $constraints */
    private function findParticipant(array $constraints, string $name): string
    {
        foreach ($constraints['users'] as $participant) {
            if (Identity::normalize($participant) === Identity::normalize($name)) {
                return $participant;
            }
        }
        throw new ApiException(404, 'participant_not_found', 'Unknown participant');
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'invalid_input', 'Name must not be empty');
        }
        Identity::normalize($name);
        return $name;
    }

    /** @param array<string,mixed> $body */
    private function string(array $body, string $key, bool $trim = true): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ApiException(400, 'invalid_input', "Field '{$key}' must be a non-empty string");
        }
        return $trim ? trim($value) : $value;
    }

    /** @param array<string,mixed> $body */
    private function email(array $body): string
    {
        $email = $this->string($body, 'email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(400, 'invalid_input', 'Invalid email');
        }
        return $email;
    }

    /** @param array<string,mixed> $body */
    private function boolean(array $body, string $key): bool
    {
        if (!is_bool($body[$key] ?? null)) {
            throw new ApiException(400, 'invalid_input', "Field '{$key}' must be a boolean");
        }
        return $body[$key];
    }
}
