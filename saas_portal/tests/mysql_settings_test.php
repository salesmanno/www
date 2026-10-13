<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../../config/mysql_settings.php';
$base = ['database' => 'fixture', 'user' => 'fixture', 'password' => 'synthetic'];
foreach ([
    ['db.example.test', '', '3306'],
    ['https://db.example.test/', '', '3306'],
    ['mysql://db.example.test:3307', '', '3307'],
    ['db.example.test:3307', '', '3307'],
    ['http://db.example.test', '3308', '3308'],
] as [$host, $port, $expected]) {
    $result = mysqlConnectionSettings($base + ['host' => $host, 'port' => $port]);
    if ($result['host'] !== 'db.example.test' || $result['port'] !== $expected) {
        throw new RuntimeException('Host normalization failed.');
    }
}
foreach ([
    ['https://db.example.test/admin', ''],
    ['mysql://user:synthetic@db.example.test', ''],
    ['https://db.example.test?option=value', ''],
    ['db.example.test;dbname=other', ''],
    ['db.example.test', '70000'],
    ['mysql://db.example.test:3307', '3308'],
] as [$host, $port]) {
    try {
        mysqlConnectionSettings($base + ['host' => $host, 'port' => $port]);
    } catch (RuntimeException $e) {
        continue;
    }
    throw new RuntimeException('Invalid settings were accepted.');
}
echo "PASS URL and hostname normalization, ports, and unsafe settings rejection.\n";
