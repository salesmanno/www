<?php

require_once __DIR__ . '/mysql_settings.php';

// Use the existing MySQL configuration supplied to the process, never credentials in source.
define('DB_HOST', mysqlEnvironmentValue('MYSQL_HOST') ?: '');
define('DB_NAME', mysqlEnvironmentValue('MYSQL_DATABASE') ?: '');
define('DB_USER', mysqlEnvironmentValue('MYSQL_USER') ?: '');
define('DB_PASS', mysqlEnvironmentValue('MYSQL_PASSWORD') === false ? '' : mysqlEnvironmentValue('MYSQL_PASSWORD'));
define('DB_CHARSET', 'utf8mb4');

function getDBConnection() {
    static $conn = null;
    if ($conn !== null) return $conn;
    $settings = mysqlConnectionSettings(mysqlRuntimeSettings());
    try {
        $dsn = 'mysql:host=' . $settings['host'] . ';port=' . $settings['port'] . ';dbname=' . $settings['database'] . ';charset=' . DB_CHARSET;
        $conn = new PDO($dsn, $settings['user'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $e) {
        // Do not log connection addresses, usernames, credentials or raw driver messages.
        error_log('MySQL connection unavailable.');
        throw new RuntimeException('Kan ikke koble til MySQL-databasen.', 0, $e);
    }
    return $conn;
}

function getDBType() {
    getDBConnection();
    return 'mysql';
}

function dbBool($value) {
    return $value ? '1' : '0';
}

function dbInterval($amount, $unit = 'MINUTE') {
    return "INTERVAL {$amount} {$unit}";
}

function closeDBConnection() {
    global $conn;
    $conn = null;
}
