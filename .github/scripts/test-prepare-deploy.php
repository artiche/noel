<?php

declare(strict_types=1);

$directory = sys_get_temp_dir() . '/noel-deploy-test-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create test directory');
}

$env = [
    'SFTP_HOST' => 'sftp.example.test',
    'SFTP_USER' => 'noel',
    'SSHPASS' => 'test-password',
    'SFTP_HOST_KEY' => 'sftp.example.test ssh-ed25519 test-key',
    'SFTP_REMOTE_ROOT' => '/site/noel',
    'SITE_URL' => 'https://example.test/noel',
    'DEPLOY_API' => 'true',
    'JWT_SECRET' => "test-only-secret-'\\-with-32-bytes\nend",
    'SMTP_USERNAME' => "user'\\name",
    'SMTP_PASSWORD' => "password'\\with\nnewline",
    'SMTP_HOST' => 'smtp.example.test',
    'SMTP_PORT' => '465',
    'SMTP_FROM_EMAIL' => 'no-reply@example.test',
    'SMTP_FROM_NAME' => "Noel's API",
    'RUNNER_TEMP' => $directory,
    'GITHUB_OUTPUT' => $directory . '/output',
];

function runPreparation(array $environment): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/prepare-deploy.php'], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start preparation');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    [$status, $stdout, $stderr] = runPreparation($env);
    check($status === 0, 'Subdirectory configuration failed: ' . $stderr);
    check($stdout === '' && $stderr === '', 'Preparation must not print secrets');
    $config = require $directory . '/noel-config.local.php';
    check($config['base_path'] === '/noel/api', 'Incorrect API base path');
    check($config['login_redirect_url'] === '/noel/connexion', 'Incorrect login redirect');
    check($config['frontend_url'] === 'https://example.test/noel', 'Incorrect frontend URL');
    check($config['jwt_secret'] === $env['JWT_SECRET'] && $config['smtp_password'] === $env['SMTP_PASSWORD'] &&
        $config['smtp_username'] === $env['SMTP_USERNAME'], 'Configuration values were not escaped correctly');
    check(file_get_contents($directory . '/output') === "base_href=/noel/\n", 'Incorrect Angular base href');

    unlink($directory . '/output');
    [$status, , $stderr] = runPreparation(array_replace($env, ['SITE_URL' => 'https://example.test/']));
    check($status === 0, 'Root configuration failed: ' . $stderr);
    $config = require $directory . '/noel-config.local.php';
    check($config['base_path'] === '/api' && $config['login_redirect_url'] === '/connexion', 'Incorrect root paths');
    check(file_get_contents($directory . '/output') === "base_href=/\n", 'Incorrect root base href');

    foreach ([
        ['SITE_URL' => 'https://example.test/noel?x=1'],
        ['SITE_URL' => 'https://example.test/../noel'],
        ['SFTP_REMOTE_ROOT' => '/site/../other'],
        ['SMTP_PORT' => '70000'],
        ['JWT_SECRET' => 'change-me-placeholder-that-is-over-32-bytes'],
    ] as $invalid) {
        [$status] = runPreparation(array_replace($env, $invalid));
        check($status !== 0, 'Invalid configuration was accepted: ' . implode(', ', array_keys($invalid)));
    }

    [$status, , $stderr] = runPreparation(array_replace($env, [
        'DEPLOY_API' => 'false',
        'JWT_SECRET' => '',
        'SMTP_PASSWORD' => '',
    ]));
    check($status === 0, 'Frontend-only deployment requires API secrets: ' . $stderr);
    echo "Deployment configuration tests passed.\n";
} finally {
    foreach (['output', 'noel-config.local.php'] as $name) {
        if (is_file($directory . '/' . $name)) {
            unlink($directory . '/' . $name);
        }
    }
    rmdir($directory);
}
