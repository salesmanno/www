<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['MYSQL_HOST', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_PORT'] as $name) {
    if (getenv($name) !== false || getenv('REDIRECT_' . $name) !== false) {
        throw new RuntimeException('Run with env -i; only synthetic test settings are allowed.');
    }
}
require __DIR__ . '/../../config/mysql_settings.php';
$_ENV = [];
$_SERVER = [];
$values = ['MYSQL_HOST'=>'fixture.local', 'MYSQL_DATABASE'=>'fixture', 'MYSQL_USER'=>'fixture', 'MYSQL_PASSWORD'=>'synthetic'];
foreach (['_ENV', '_SERVER'] as $global) {
    $GLOBALS[$global] = $values;
    $settings = mysqlConnectionSettings(mysqlRuntimeSettings());
    if ($settings['host'] !== 'fixture.local' || $settings['password'] !== 'synthetic') {
        throw new RuntimeException('PHP environment source failed.');
    }
    $GLOBALS[$global] = [];
}
$_SERVER = ['HTTP_MYSQL_HOST'=>'untrusted.local'];
$_GET = $_POST = $_COOKIE = ['MYSQL_HOST'=>'untrusted.local'];
if (mysqlEnvironmentValue('MYSQL_HOST') !== false) {
    throw new RuntimeException('Client input was accepted as configuration.');
}
$_SERVER = ['REDIRECT_MYSQL_HOST'=>'redirect.local'];
if (mysqlEnvironmentValue('MYSQL_HOST') !== 'redirect.local') throw new RuntimeException('Apache redirect environment failed.');
$_SERVER['MYSQL_HOST'] = 'server.local';
$_ENV['MYSQL_HOST'] = 'env.local';
if (mysqlEnvironmentValue('MYSQL_HOST') !== 'env.local') throw new RuntimeException('Environment source precedence failed.');
putenv('MYSQL_HOST=process.local');
if (mysqlEnvironmentValue('MYSQL_HOST') !== 'process.local') throw new RuntimeException('Process environment precedence failed.');
putenv('MYSQL_HOST');
$_ENV = $values;
$_ENV['MYSQL_PASSWORD'] = '';
if (mysqlConnectionSettings(mysqlRuntimeSettings())['password'] !== '') throw new RuntimeException('Explicit empty password was lost.');
$_ENV['MYSQL_HOST'] = [];
try {
    mysqlRuntimeSettings();
    throw new LogicException('Non-string environment setting was accepted.');
} catch (MysqlConfigurationException $expected) {}
echo "PASS process, ENV, SERVER and Apache redirect sources, precedence, empty password and client-input rejection.\n";
