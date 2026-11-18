<?php

declare(strict_types=1);

use Noel\Api\Domain\ShareDraftService;
use Noel\Api\Infrastructure\ShareDraftStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/Bootstrap.php';

try {
    $base = dirname(__DIR__);
    $config = require $base . '/data/config.php';
    $localPath = $base . '/data/config.local.php';
    if (is_file($localPath)) {
        $local = require $localPath;
        if (!is_array($local)) {
            throw new RuntimeException('Invalid local configuration');
        }
        $config = array_replace($config, $local);
    }
    $directory = $config['share_draft_directory'] ?? null;
    if (!is_string($directory) || trim($directory) === '') {
        throw new RuntimeException('Invalid share draft directory');
    }
    if (!str_starts_with($directory, '/')) {
        $directory = $base . '/' . $directory;
    }
    $service = new ShareDraftService(new ShareDraftStore($directory));
    printf("Removed %d expired share drafts or temporary files.\n", $service->cleanup(time()));
} catch (Throwable $exception) {
    fwrite(STDERR, "Share cleanup failed: " . $exception->getMessage() . PHP_EOL);
    exit(1);
}
