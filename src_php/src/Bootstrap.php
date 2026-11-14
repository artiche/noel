<?php

declare(strict_types=1);

spl_autoload_register(static function (string $className): void {
    $prefix = 'Noel\\Api\\';
    if (!str_starts_with($className, $prefix)) {
        return;
    }

    $relative = substr($className, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . $relativePath;

    if (is_file($fullPath)) {
        require_once $fullPath;
    }
});
