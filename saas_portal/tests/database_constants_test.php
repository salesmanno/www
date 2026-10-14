<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../../config/database.php';
$settings = databaseConnectionSettings();

if ($settings['host'] === '' || $settings['database'] === '' ||
    $settings['user'] === '' || $settings['password'] === false ||
    $settings['port'] === '') {
    throw new RuntimeException('Database settings are incomplete.');
}

echo "PASS configured database constants are valid; values were not displayed.\n";
