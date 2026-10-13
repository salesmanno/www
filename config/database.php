<?php

require_once __DIR__ . '/mysql_configuration.php';

// Actual credentials come from the environment or a private file outside the web root.
define('DB_CHARSET', 'utf8mb4');

function getDBConnection() {
    static $conn = null;
    if ($conn !== null) return $conn;
    $environment = [];
    foreach (['MYSQL_HOST', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_PORT', 'MYSQL_CONFIG_FILE'] as $key) {
        $environment[$key] = getenv($key);
    }
    $settings = loadMysqlConfiguration($environment, dirname(__DIR__), $_SERVER['DOCUMENT_ROOT'] ?? '');
    // Preserve the legacy constants used by maintenance scripts after connecting.
    foreach (['DB_HOST' => 'host', 'DB_NAME' => 'database', 'DB_USER' => 'user', 'DB_PASS' => 'password'] as $constant => $key) {
        if (!defined($constant)) define($constant, $settings[$key]);
    }
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
