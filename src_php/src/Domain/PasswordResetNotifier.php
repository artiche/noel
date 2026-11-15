<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

interface PasswordResetNotifier
{
    public function sendPasswordResetEmail(string $login, string $email, string $temporaryPassword, string $confirmationUrl, int $expiresAt): void;
}

