<?php

class MysqlConfigurationException extends RuntimeException {}

/** Read only server-managed settings, never HTTP_*, GET, POST or cookies. */
function mysqlEnvironmentValue(string $name) {
    $value = getenv($name);
    if ($value !== false) return $value;
    foreach ([$_ENV, $_SERVER] as $source) {
        if (array_key_exists($name, $source)) {
            if (!is_string($source[$name])) {
                throw new MysqlConfigurationException('MySQL-miljøvariablene er ugyldige.');
            }
            return $source[$name];
        }
    }
    // Apache may preserve SetEnv settings under REDIRECT_ after an internal rewrite.
    $value = getenv('REDIRECT_' . $name);
    if ($value !== false) return $value;
    if (array_key_exists('REDIRECT_' . $name, $_SERVER)) {
        if (!is_string($_SERVER['REDIRECT_' . $name])) {
            throw new MysqlConfigurationException('MySQL-miljøvariablene er ugyldige.');
        }
        return $_SERVER['REDIRECT_' . $name];
    }
    return false;
}

function mysqlRuntimeSettings(): array {
    $names = [
        'host' => 'MYSQL_HOST', 'database' => 'MYSQL_DATABASE',
        'user' => 'MYSQL_USER', 'password' => 'MYSQL_PASSWORD', 'port' => 'MYSQL_PORT',
    ];
    $settings = [];
    foreach ($names as $key => $name) {
        $settings[$key] = mysqlEnvironmentValue($name);
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
            throw new MysqlConfigurationException('MySQL-innstillingene er ugyldige.');
        }
        $host = $url['host'];
        if (isset($url['port'])) {
            if ($port !== '' && (int)$port !== $url['port']) {
                throw new MysqlConfigurationException('MySQL-portinnstillingene er motstridende.');
            }
            $port = (string)$url['port'];
        }
    } elseif (preg_match('/^([^:]+):([0-9]+)$/D', $host, $parts)) {
        $host = $parts[1];
        if ($port !== '' && (int)$port !== (int)$parts[2]) {
            throw new MysqlConfigurationException('MySQL-portinnstillingene er motstridende.');
        }
        $port = $parts[2];
    }
    $port = $port === '' ? '3306' : $port;
    $database = (string)($settings['database'] ?? '');
    if ($host === '' || $database === '' || empty($settings['user']) ||
        !array_key_exists('password', $settings) || $settings['password'] === false) {
        throw new MysqlConfigurationException('MySQL-innstillingene er ikke konfigurert.');
    }
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 ||
        preg_match('/[;\x00-\x20\/?#@]/', $host) || preg_match('/[;\x00-\x1f]/', $database)) {
        throw new MysqlConfigurationException('MySQL-innstillingene er ugyldige.');
    }
    return array_merge($settings, ['host' => $host, 'database' => $database, 'port' => $port]);
}
