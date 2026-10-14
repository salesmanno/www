<?php

require_once __DIR__ . '/mysql_settings.php';

// MySQL ONLY - Domeneshop production database
define('DB_HOST', 'http://salesmanno04.mysql.domeneshop.no/');
define('DB_NAME', 'salesmanno04');
define('DB_USER', 'salesmanno04');
define('DB_PASS', 'samle-lime-2Salgs-kd');
define('DB_CHARSET', 'utf8mb4');
// Values explicitly defined before this file takes precedence. This supports
// installations that provide DB_* constants in a private bootstrap, while
// keeping the existing MYSQL_* environment-variable setup as the default.
if (!defined('DB_HOST')) define('DB_HOST', mysqlEnvironmentValue('MYSQL_HOST'));
if (!defined('DB_NAME')) define('DB_NAME', mysqlEnvironmentValue('MYSQL_DATABASE'));
if (!defined('DB_USER')) define('DB_USER', mysqlEnvironmentValue('MYSQL_USER'));
if (!defined('DB_PASS')) define('DB_PASS', mysqlEnvironmentValue('MYSQL_PASSWORD'));
if (!defined('DB_PORT')) define('DB_PORT', mysqlEnvironmentValue('MYSQL_PORT'));
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

function databaseConnectionSettings(): array {
    return mysqlConnectionSettings([
        'host' => DB_HOST,
        'database' => DB_NAME,
        'user' => DB_USER,
        'password' => DB_PASS,
        'port' => DB_PORT,
    ]);
}

function getDBConnection() {
    static $conn = null;
    if ($conn !== null) return $conn;
    $settings = databaseConnectionSettings();
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
