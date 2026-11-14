<?php

declare(strict_types=1);

use Noel\Api\Domain\DrawService;
use Noel\Api\Domain\EdgeScoreCalculator;
use Noel\Api\Domain\AuthService;
use Noel\Api\Domain\JwtService;
use Noel\Api\Domain\WeightedBipartiteMatcher;
use Noel\Api\Http\App;
use Noel\Api\Infrastructure\JsonRepository;

require_once dirname(__DIR__) . '/src/Bootstrap.php';

$repository = new JsonRepository(dirname(__DIR__, 1));
$service = new DrawService(new EdgeScoreCalculator(), new WeightedBipartiteMatcher());
$jwtSecret = $_SERVER['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '';
$cookieName = $_SERVER['JWT_COOKIE_NAME'] ?? getenv('JWT_COOKIE_NAME') ?: 'noel_session';
$ttlRaw = $_SERVER['JWT_TTL_SECONDS'] ?? getenv('JWT_TTL_SECONDS') ?: '1800';
$jwtTtlSeconds = ctype_digit((string) $ttlRaw) ? (int) $ttlRaw : 1800;

$authService = new AuthService(new JwtService($jwtSecret));
$app = new App($repository, $service, $authService, $cookieName, $jwtTtlSeconds, '/');

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$basePath = $_SERVER['BASE_PATH'] ?? getenv('BASE_PATH') ?: '';
$basePath = normalizeBasePath($basePath);

$path = is_string($rawPath) ? rtrim($rawPath, '/') : '/';
if ($path === '') {
    $path = '/';
}
$path = stripBasePath($path, $basePath);
if ($basePath !== '') {
    $app = new App($repository, $service, $authService, $cookieName, $jwtTtlSeconds, $basePath);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$query = $_GET;
$input = file_get_contents('php://input');
$jsonBody = null;
if (is_string($input) && $input !== '') {
    $decoded = json_decode($input, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}

$response = $app->handle($method, $path, $query, $jsonBody, $_COOKIE);

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

http_response_code($response['status']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

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
