<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 2) {
    exit(1);
}

$host = $argv[1];
$seen = [];
$addresses = [];
for ($depth = 0; $depth < 8; $depth++) {
    if (isset($seen[$host])) {
        exit(1);
    }
    $seen[$host] = true;
    $next = null;
    foreach ([DNS_A, DNS_AAAA] as $type) {
        $records = dns_get_record($host, $type);
        if ($records === false) {
            exit(1);
        }
        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'A' && isset($record['ip'])) {
                $addresses[] = $record['ip'];
            } elseif (($record['type'] ?? '') === 'AAAA' && isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            } elseif (($record['type'] ?? '') === 'CNAME' && isset($record['target'])) {
                if ($next !== null && $next !== $record['target']) {
                    exit(1);
                }
                $next = $record['target'];
            }
        }
    }
    if (count($addresses) > 64) {
        exit(1);
    }
    if ($next === null) {
        echo json_encode(array_values(array_unique($addresses)));
        exit(0);
    }
    $host = $next;
}
exit(1);
