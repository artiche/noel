<?php

declare(strict_types=1);

use Noel\Api\Domain\DrawService;
use Noel\Api\Domain\EdgeScoreCalculator;
use Noel\Api\Domain\WeightedBipartiteMatcher;
use Noel\Api\Http\App;
use Noel\Api\Infrastructure\JsonRepository;

require_once dirname(__DIR__) . '/src/Bootstrap.php';

$repository = new JsonRepository(dirname(__DIR__, 1));
$service = new DrawService(new EdgeScoreCalculator(), new WeightedBipartiteMatcher());
$app = new App($repository, $service);

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($rawPath) ? rtrim($rawPath, '/') : '/';
if ($path === '') {
    $path = '/';
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

$response = $app->handle($method, $path, $query, $jsonBody);

http_response_code($response['status']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
