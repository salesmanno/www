<?php

/**
 * Accept a hostname or a host URL without changing stored secrets.
 * Never include supplied settings in error messages.
 */
function mysqlConnectionSettings(array $settings): array {
    $host = trim((string)($settings['host'] ?? ''));
    $port = trim((string)($settings['port'] ?? ''));
    if (str_contains($host, '://')) {
        $url = parse_url($host);
        if (!is_array($url) || empty($url['host']) ||
            !in_array(strtolower($url['scheme'] ?? ''), ['mysql', 'mariadb', 'http', 'https'], true) ||
            isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
            (!empty($url['path']) && $url['path'] !== '/')) {
            throw new RuntimeException('MySQL-innstillingene er ugyldige.');
        }
        $host = $url['host'];
        if (isset($url['port'])) {
            if ($port !== '' && (int)$port !== $url['port']) {
                throw new RuntimeException('MySQL-portinnstillingene er motstridende.');
            }
            $port = (string)$url['port'];
        }
    } elseif (preg_match('/^([^:]+):([0-9]+)$/D', $host, $parts)) {
        $host = $parts[1];
        if ($port !== '' && (int)$port !== (int)$parts[2]) {
            throw new RuntimeException('MySQL-portinnstillingene er motstridende.');
        }
        $port = $parts[2];
    }
    $port = $port === '' ? '3306' : $port;
    $database = (string)($settings['database'] ?? '');
    if ($host === '' || $database === '' || empty($settings['user']) ||
        !array_key_exists('password', $settings) || $settings['password'] === false) {
        throw new RuntimeException('MySQL-innstillingene er ikke konfigurert.');
    }
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 ||
        preg_match('/[;\x00-\x20\/?#@]/', $host) || preg_match('/[;\x00-\x1f]/', $database)) {
        throw new RuntimeException('MySQL-innstillingene er ugyldige.');
    }
    return array_merge($settings, ['host' => $host, 'database' => $database, 'port' => $port]);
}
