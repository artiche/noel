<?php

declare(strict_types=1);

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Variable GitHub manquante : {$name}");
    }

    return $value;
}

try {
    $host = requiredEnv('SFTP_HOST');
    $user = requiredEnv('SFTP_USER');
    requiredEnv('SSHPASS');
    requiredEnv('SFTP_HOST_KEY');
    $remoteRoot = requiredEnv('SFTP_REMOTE_ROOT');
    $siteUrl = rtrim(requiredEnv('SITE_URL'), '/');
    $parts = parse_url($siteUrl);
    $path = is_array($parts) ? ($parts['path'] ?? '') : '';

    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', $host) !== 1 ||
        preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.@+-]*$/D', $user) !== 1) {
        throw new RuntimeException('SFTP_HOST ou SFTP_USER invalide.');
    }
    if (preg_match('#^/(?:[a-zA-Z0-9_-][a-zA-Z0-9_.-]*/?)*$#D', $remoteRoot) !== 1) {
        throw new RuntimeException('SFTP_REMOTE_ROOT doit être un chemin absolu sans espaces ni segments . ou ...');
    }
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
        !isset($parts['host']) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', $parts['host']) !== 1 ||
        isset($parts['user']) || isset($parts['pass']) ||
        isset($parts['query']) || isset($parts['fragment']) ||
        preg_match('#^(?:/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)*$#D', $path) !== 1) {
        throw new RuntimeException('SITE_URL doit être une URL HTTPS sans identifiants, requête ni fragment, avec un chemin simple.');
    }

    if (getenv('DEPLOY_API') === 'true') {
        $jwtSecret = requiredEnv('JWT_SECRET');
        if (strlen(trim($jwtSecret)) < 32 || str_starts_with(strtoupper(trim($jwtSecret)), 'CHANGE_ME') ||
            str_starts_with(strtolower(trim($jwtSecret)), 'change-me')) {
            throw new RuntimeException('JWT_SECRET doit être aléatoire, ne pas être un placeholder et comporter au moins 32 octets.');
        }
        $smtpPort = requiredEnv('SMTP_PORT');
        if (!ctype_digit($smtpPort) || (int) $smtpPort < 1 || (int) $smtpPort > 65535) {
            throw new RuntimeException('SMTP_PORT doit être compris entre 1 et 65535.');
        }
        $config = [
            'base_path' => $path . '/api',
            'jwt_secret' => $jwtSecret,
            'login_redirect_url' => $path . '/connexion',
            'frontend_url' => $siteUrl,
            'smtp_host' => requiredEnv('SMTP_HOST'),
            'smtp_port' => (int) $smtpPort,
            'smtp_username' => requiredEnv('SMTP_USERNAME'),
            'smtp_password' => requiredEnv('SMTP_PASSWORD'),
            'smtp_from_email' => requiredEnv('SMTP_FROM_EMAIL'),
            'smtp_from_name' => requiredEnv('SMTP_FROM_NAME'),
        ];
        $metadataEnabled = getenv('SHARE_METADATA_ENABLED');
        if ($metadataEnabled !== false && $metadataEnabled !== '') {
            if (!in_array($metadataEnabled, ['true', 'false'], true)) {
                throw new RuntimeException('SHARE_METADATA_ENABLED doit valoir true ou false.');
            }
            $config['share_metadata_enabled'] = $metadataEnabled === 'true';
        }
        $metadataPhp = getenv('SHARE_METADATA_DNS_PHP');
        if ($metadataPhp !== false && $metadataPhp !== '') {
            if ($metadataPhp !== 'php' && preg_match('#^/(?:[a-zA-Z0-9_-][a-zA-Z0-9_.-]*/)*[a-zA-Z0-9_-][a-zA-Z0-9_.-]*$#D', $metadataPhp) !== 1) {
                throw new RuntimeException('SHARE_METADATA_DNS_PHP doit valoir php ou un chemin absolu simple.');
            }
            $config['share_metadata_dns_php'] = $metadataPhp;
        }
        if (filter_var($config['smtp_from_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('SMTP_FROM_EMAIL doit être une adresse email valide.');
        }
        $configFile = requiredEnv('RUNNER_TEMP') . '/noel-config.local.php';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configFile, $contents, LOCK_EX) === false || !chmod($configFile, 0600)) {
            throw new RuntimeException('Impossible de créer la configuration locale.');
        }
    }

    $runnerTemp = requiredEnv('RUNNER_TEMP');
    foreach ([
        ['src_angular/public/.htaccess', 'noel-ui.htaccess', $path . '/'],
        ['src_php/.htaccess', 'noel-api.htaccess', $path . '/api/'],
    ] as [$source, $destination, $rewriteBase]) {
        $template = file_get_contents(__DIR__ . '/../../' . $source);
        if ($template === false || substr_count($template, 'RewriteEngine On') !== 1) {
            throw new RuntimeException("Impossible de préparer le fichier {$source}.");
        }
        $contents = str_replace('RewriteEngine On', "RewriteEngine On\nRewriteBase {$rewriteBase}", $template);
        if (file_put_contents($runnerTemp . '/' . $destination, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Impossible de créer le fichier {$destination}.");
        }
    }

    if (file_put_contents(requiredEnv('GITHUB_OUTPUT'), "base_href={$path}/\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Impossible de transmettre le chemin public.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
