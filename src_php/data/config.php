<?php

declare(strict_types=1);

return [
    // Template versionné: placez les secrets réels dans data/config.local.php.
    'base_path' => '',

    'jwt_secret' => 'CHANGE_ME_JWT_SECRET',
    'jwt_cookie_name' => 'noel_session',
    'jwt_ttl_seconds' => 1800,

    'password_reset_ttl_seconds' => 3600,
    'frontend_url' => 'https://example.com',
    'login_redirect_url' => '/login',

    'users_file_path' => 'data/users.json',
    'password_resets_file_path' => 'data/passwordResets.json',
    'wishlist_directory' => 'data/wishlists',
    'share_draft_directory' => 'data/share-drafts',
    'share_tokens_file_path' => 'data/share-tokens.json',
    'share_allow_insecure_local' => false,
    'share_metadata_enabled' => true,
    'share_metadata_dns_php' => 'php',

    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 25,
    'smtp_username' => 'CHANGE_ME_SMTP_USERNAME',
    'smtp_password' => 'CHANGE_ME_SMTP_PASSWORD',
    'smtp_from_email' => 'CHANGE_ME_FROM_EMAIL@example.com',
    'smtp_from_name' => 'Noel API',
];
