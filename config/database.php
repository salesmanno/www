<?php

// Use the existing MySQL configuration supplied to the process, never credentials in source.
define('DB_HOST', getenv('MYSQL_HOST') ?: '');
define('DB_NAME', getenv('MYSQL_DATABASE') ?: '');
define('DB_USER', getenv('MYSQL_USER') ?: '');
define('DB_PASS', getenv('MYSQL_PASSWORD') === false ? '' : getenv('MYSQL_PASSWORD'));
define('DB_CHARSET', 'utf8mb4');

function getDBConnection() {
    static $conn = null;
    if ($conn !== null) return $conn;
    if (DB_HOST === '' || DB_NAME === '' || DB_USER === '' || getenv('MYSQL_PASSWORD') === false) {
        throw new RuntimeException('MySQL-innstillingene er ikke konfigurert.');
    }
    $port = getenv('MYSQL_PORT') ?: '3306';
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 ||
        preg_match('/[;\x00-\x1f]/', DB_HOST . DB_NAME)) {
        throw new RuntimeException('MySQL-innstillingene er ugyldige.');
    }
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $conn = new PDO($dsn, DB_USER, DB_PASS, [
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
