<?php

declare(strict_types=1);

use Noel\Api\Domain\DrawService;
use Noel\Api\Domain\EdgeScoreCalculator;
use Noel\Api\Domain\WeightedBipartiteMatcher;
use Noel\Api\Http\App;
use Noel\Api\Infrastructure\JsonRepository;

require_once dirname(__DIR__) . '/src/Bootstrap.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertEquals(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

$repo = new JsonRepository(dirname(__DIR__));
$service = new DrawService(new EdgeScoreCalculator(), new WeightedBipartiteMatcher());
$app = new App($repo, $service);

$health = $app->handle('GET', '/health', [], null);
assertEquals(200, $health['status'], 'health endpoint should return 200');
assertEquals('ok', $health['body']['data']['status'], 'health endpoint should return ok');

$possible = $app->handle('GET', '/possible', ['year' => '2027'], null);
assertEquals(200, $possible['status'], 'possible endpoint should return 200');
assertTrue(isset($possible['body']['data']['possibleRecipientsByGiver']['Maxime']), 'possible should include Maxime');

$printExisting = $app->handle('GET', '/draw/2026', [], null);
assertEquals(200, $printExisting['status'], 'print existing draw should return 200');
assertTrue(count($printExisting['body']['data']['lines']) > 0, 'print existing draw should return lines');

$printMissing = $app->handle('GET', '/draw/2099', [], null);
assertEquals(404, $printMissing['status'], 'missing draw should return 404');
assertEquals('draw_not_found', $printMissing['body']['error']['code'], 'missing draw code should be draw_not_found');

echo "All src_php tests passed.\n";
