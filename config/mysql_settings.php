<?php

/**
 * Webhosts may not provide the Replit environment. A host-managed PHP
 * configuration outside the document root is supported instead.
 */
function mysqlRuntimeSettings(): array {
    $names = [
        'host' => 'MYSQL_HOST', 'database' => 'MYSQL_DATABASE',
        'user' => 'MYSQL_USER', 'password' => 'MYSQL_PASSWORD', 'port' => 'MYSQL_PORT',
    ];
    $settings = [];
    foreach ($names as $key => $name) {
        $settings[$key] = getenv($name);
    }
    if ($settings['host'] !== false && $settings['database'] !== false &&
        $settings['user'] !== false && $settings['password'] !== false) {
        return $settings;
    }
    $explicitPath = getenv('MYSQL_CONFIG_FILE');
    $path = $explicitPath ?: dirname(__DIR__, 2) . '/salesman-private/mysql.php';
    if (!is_file($path)) {
        if ($explicitPath) throw new RuntimeException('Privat MySQL-konfigurasjon mangler.');
        return $settings;
    }
    $private = mysqlPrivateSettings($path, $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));
    foreach ($names as $key => $name) {
        if ($settings[$key] === false && array_key_exists($key, $private)) {
            $settings[$key] = $private[$key];
        }
    }
    return $settings;
}

function mysqlPrivateSettings(string $path, string $documentRoot): array {
    $real = realpath($path);
    $root = realpath($documentRoot);
    if ($real === false || !is_readable($real) || $root === false ||
        $real === $root || str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Privat MySQL-konfigurasjon må ligge utenfor webroten.');
    }
    $settings = (static function ($file) { return require $file; })($real);
    if (!is_array($settings)) {
        throw new RuntimeException('Privat MySQL-konfigurasjon er ugyldig.');
    }
    return $settings;
}

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
