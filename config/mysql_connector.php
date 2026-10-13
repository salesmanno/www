<?php

/**
 * Retry only a failed connection over IPv4 resolved from the SAME configured
 * hostname. Never retry authentication, SQL, transactions or another database.
 * The injectable callbacks keep network failure tests isolated.
 */
function connectConfiguredMysql(array $settings, ?callable $connect = null, ?callable $resolve = null): PDO {
    $connect ??= static function (string $host, array $settings): PDO {
        $dsn = 'mysql:host=' . $host . ';port=' . $settings['port']
            . ';dbname=' . $settings['database'] . ';charset=utf8mb4';
        return new PDO($dsn, $settings['user'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    };
    $resolve ??= static fn(string $host): array => gethostbynamel($host) ?: [];
    try {
        return $connect($settings['host'], $settings);
    } catch (PDOException $initial) {
        $driver = $initial->errorInfo[1] ?? $initial->getCode();
        if (!in_array((int)$driver, [2002, 2003], true) ||
            $settings['host'] === 'localhost' ||
            filter_var($settings['host'], FILTER_VALIDATE_IP)) {
            throw $initial;
        }
        foreach ($resolve($settings['host']) as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            // One extra connection attempt only; no unbounded network retries.
            $connection = $connect($address, $settings);
            error_log('MySQL connection recovered using IPv4 for the configured host.');
            return $connection;
        }
        throw $initial;
    }
}
