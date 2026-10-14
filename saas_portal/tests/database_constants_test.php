<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Synthetic fixture values only. This test does not connect to MySQL or use secrets.
define('DB_HOST', 'mysql://fixture.example.test:3307');
define('DB_NAME', 'fixture_database');
define('DB_USER', 'fixture_user');
define('DB_PASS', 'fixture_password');
define('DB_PORT', '');
require __DIR__ . '/../../config/database.php';

$settings = databaseConnectionSettings();
if ($settings['host'] !== 'fixture.example.test' ||
    $settings['port'] !== '3307' ||
    $settings['database'] !== 'fixture_database' ||
    $settings['user'] !== 'fixture_user' ||
    $settings['password'] !== 'fixture_password') {
    throw new RuntimeException('Explicit database constants were not used.');
}
echo "PASS explicit database constants and host normalization without a database connection.\n";
