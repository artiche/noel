<?php

declare(strict_types=1);

use Noel\Api\Domain\DrawService;
use Noel\Api\Domain\EdgeScoreCalculator;
use Noel\Api\Domain\AuthService;
use Noel\Api\Domain\JwtService;
use Noel\Api\Domain\PasswordResetService;
use Noel\Api\Domain\ShareDraftService;
use Noel\Api\Domain\ShareTokenService;
use Noel\Api\Domain\WeightedBipartiteMatcher;
use Noel\Api\Http\App;
use Noel\Api\Infrastructure\JsonRepository;
use Noel\Api\Infrastructure\CurlMetadataNetwork;
use Noel\Api\Infrastructure\OpenGraphFetcher;
use Noel\Api\Infrastructure\ShareDraftStore;
use Noel\Api\Infrastructure\SmtpPasswordResetNotifier;

require_once dirname(__DIR__) . '/src/Bootstrap.php';
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

try {
    $configPath = dirname(__DIR__) . '/data/config.php';
    $localConfigPath = dirname(__DIR__) . '/data/config.local.php';
    $config = loadConfig($configPath, $localConfigPath);

    $repository = new JsonRepository(dirname(__DIR__, 1));
    $service = new DrawService(new EdgeScoreCalculator(), new WeightedBipartiteMatcher());

    $basePath = normalizeBasePath(readOptionalConfigString($config, 'base_path', ''));
    $jwtSecret = readRequiredConfigString($config, 'jwt_secret');
    $cookieName = readRequiredConfigString($config, 'jwt_cookie_name');
    $jwtTtlSeconds = readRequiredConfigInt($config, 'jwt_ttl_seconds');
    $passwordResetTtlSeconds = readRequiredConfigInt($config, 'password_reset_ttl_seconds');
    $loginRedirectUrl = readRequiredConfigString($config, 'login_redirect_url');
    $smtpHost = readRequiredConfigString($config, 'smtp_host');
    $smtpPort = readRequiredConfigInt($config, 'smtp_port');
    $smtpUsername = readRequiredConfigString($config, 'smtp_username');
    $smtpPassword = readRequiredConfigString($config, 'smtp_password');
    $smtpFromEmail = readRequiredConfigString($config, 'smtp_from_email');
    $smtpFromName = readRequiredConfigString($config, 'smtp_from_name');
    $usersFilePath = readRequiredConfigString($config, 'users_file_path');
    $passwordResetsFilePath = readRequiredConfigString($config, 'password_resets_file_path');
    $wishlistDirectory = readRequiredConfigString($config, 'wishlist_directory');
    $frontendUrl = readRequiredConfigString($config, 'frontend_url');
    $shareDirectory = readRequiredConfigString($config, 'share_draft_directory');
    $shareTokensFilePath = readRequiredConfigString($config, 'share_tokens_file_path');
    $allowInsecureShare = $config['share_allow_insecure_local'] ?? false;
    if (!is_bool($allowInsecureShare)) {
        throw new RuntimeException('Invalid share_allow_insecure_local configuration');
    }
    $metadataEnabled = $config['share_metadata_enabled'] ?? true;
    if (!is_bool($metadataEnabled)) {
        throw new RuntimeException('Invalid share_metadata_enabled configuration');
    }
    $metadataPhp = readRequiredConfigString($config, 'share_metadata_dns_php');

    $jwtService = new JwtService($jwtSecret);
    $authService = new AuthService($jwtService);
    $mailNotifier = new SmtpPasswordResetNotifier(
        $smtpHost,
        $smtpPort,
        $smtpUsername,
        $smtpPassword,
        $smtpFromEmail,
        $smtpFromName
    );
    $passwordResetService = new PasswordResetService($jwtService, $mailNotifier, $passwordResetTtlSeconds, $frontendUrl);
    $app = new App(
        $repository,
        $service,
        $authService,
        $passwordResetService,
        $cookieName,
        $jwtTtlSeconds,
        $basePath === '' ? '/' : $basePath,
        $usersFilePath,
        $passwordResetsFilePath,
        $loginRedirectUrl,
        'data/constraints.json',
        'data/history.json',
        $wishlistDirectory,
        $mailNotifier,
        $frontendUrl,
        new ShareDraftService(new ShareDraftStore(resolveStoragePath($shareDirectory))),
        new ShareTokenService($repository, $shareTokensFilePath),
        $metadataEnabled ? new OpenGraphFetcher(new CurlMetadataNetwork($metadataPhp)) : null
    );

    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($rawPath) ? rtrim($rawPath, '/') : '/';
    if ($path === '') {
        $path = '/';
    }
    $path = stripBasePath($path, $basePath);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $query = $_GET;
    $inputLimit = 29 * 1024 * 1024;
    $multipartShare = $method === 'POST' && ($path === '/share-target' || $path === '/wish');
    if ($multipartShare) {
        $length = $_SERVER['CONTENT_LENGTH'] ?? null;
        $postLimit = ini_parse_quantity((string) ini_get('post_max_size'));
        if (!is_string($length) || !ctype_digit($length)) {
            http_response_code(411);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
            echo json_encode(['error' => ['code' => 'length_required', 'message' => 'Content length is required']]);
            exit;
        }
        if ((int) $length > 5_200_000 || ($postLimit > 0 && (int) $length > $postLimit)) {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
            echo json_encode(['error' => ['code' => 'share_too_large', 'message' => 'Share exceeds its size limit']]);
            exit;
        }
        if (filter_var(ini_get('enable_post_data_reading'), FILTER_VALIDATE_BOOLEAN)) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
            echo json_encode(['error' => ['code' => 'share_multipart_unavailable', 'message' => 'Multipart handling is not configured']]);
            exit;
        }
    }
    $boundedJsonShare = $method === 'POST' && ($path === '/share-tokens'
        || str_starts_with($path, '/share-tokens/') || str_starts_with($path, '/share-drafts/'));
    $input = $multipartShare ? file_get_contents('php://input', false, null, 0, 5_200_001)
        : ($method === 'PUT' && $path === '/wishlist'
            ? file_get_contents('php://input', false, null, 0, $inputLimit + 1)
            : ($boundedJsonShare ? file_get_contents('php://input', false, null, 0, 131_073) : file_get_contents('php://input')));
    if (($multipartShare && (!is_string($input) || strlen($input) !== (int) $length))
        || ($boundedJsonShare && is_string($input) && strlen($input) > 131_072)) {
        http_response_code($multipartShare ? 400 : 413);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        echo json_encode(['error' => [
            'code' => $multipartShare ? 'invalid_multipart' : 'share_too_large',
            'message' => $multipartShare ? 'Incomplete multipart body' : 'Share exceeds its size limit',
        ]]);
        exit;
    }
    if ($method === 'PUT' && $path === '/wishlist' && is_string($input) && strlen($input) > $inputLimit) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => ['code' => 'wishlist_too_large', 'message' => 'Wishlist exceeds its size limit']]);
        exit;
    }
    $jsonBody = null;
    if (!$multipartShare && is_string($input) && $input !== '') {
        $decoded = json_decode($input, true);
        if (is_array($decoded)) {
            $jsonBody = $decoded;
        }
    }

    $request = [
        'contentType' => $_SERVER['CONTENT_TYPE'] ?? '',
        'body' => $multipartShare ? $input : null,
        'remoteAddress' => $_SERVER['REMOTE_ADDR'] ?? '',
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        'origin' => $_SERVER['HTTP_ORIGIN'] ?? '',
        'secure' => in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
            || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https'
            || ($allowInsecureShare && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)),
    ];
    $response = $app->handle($method, $path, $query, $jsonBody, $_COOKIE, $request);

    foreach ($response['cookies'] ?? [] as $cookie) {
        setcookie(
            $cookie['name'],
            $cookie['value'],
            [
                'expires' => (int) $cookie['expires'],
                'path' => (string) $cookie['path'],
                'secure' => (bool) $cookie['secure'],
                'httponly' => (bool) $cookie['httponly'],
                'samesite' => (string) $cookie['samesite'],
            ]
        );
    }
    foreach ($response['headers'] ?? [] as $headerName => $headerValue) {
        header((string) $headerName . ': ' . (string) $headerValue);
    }

    http_response_code($response['status']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $exception) {
    error_log('[bootstrap_error] ' . $exception->getMessage() . PHP_EOL . $exception->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        [
            'error' => [
                'code' => 'bootstrap_error',
                'message' => $exception->getMessage(),
            ],
        ],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
}

function resolveStoragePath(string $path): string
{
    return str_starts_with($path, '/') ? $path : dirname(__DIR__) . '/' . $path;
}

function normalizeBasePath(string $basePath): string
{
    $basePath = trim($basePath);
    if ($basePath === '' || $basePath === '/') {
        return '';
    }

    $basePath = '/' . trim($basePath, '/');
    return rtrim($basePath, '/');
}

function stripBasePath(string $requestPath, string $basePath): string
{
    if ($basePath === '') {
        return $requestPath;
    }

    if ($requestPath === $basePath) {
        return '/';
    }

    $prefix = $basePath . '/';
    if (str_starts_with($requestPath, $prefix)) {
        return '/' . ltrim(substr($requestPath, strlen($basePath)), '/');
    }

    return $requestPath;
}

/** @return array<string,mixed> */
function loadConfig(string $path, string $localPath): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Config file not found: {$path}");
    }

    $baseConfig = require $path;
    if (!is_array($baseConfig)) {
        throw new RuntimeException("Config file must return an array: {$path}");
    }

    if (!is_file($localPath)) {
        return $baseConfig;
    }

    $localConfig = require $localPath;
    if (!is_array($localConfig)) {
        throw new RuntimeException("Local config file must return an array: {$localPath}");
    }

    return array_replace($baseConfig, $localConfig);
}

/** @param array<string,mixed> $config */
function readRequiredConfigString(array $config, string $key): string
{
    $value = $config[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Missing or invalid config key '{$key}'");
    }
    return $value;
}

/** @param array<string,mixed> $config */
function readOptionalConfigString(array $config, string $key, string $defaultValue): string
{
    $value = $config[$key] ?? null;
    if ($value === null) {
        return $defaultValue;
    }
    if (!is_string($value)) {
        throw new RuntimeException("Invalid config key '{$key}': expected string");
    }
    return $value;
}

/** @param array<string,mixed> $config */
function readRequiredConfigInt(array $config, string $key): int
{
    $value = $config[$key] ?? null;
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }
    throw new RuntimeException("Missing or invalid config key '{$key}' (expected integer)");
}
