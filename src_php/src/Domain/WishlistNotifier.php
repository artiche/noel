<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

interface WishlistNotifier
{
    public function sendWishlistEmail(string $giverLogin, string $email, string $participant, string $url): void;
}
