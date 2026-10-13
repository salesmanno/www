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

$fixture = sys_get_temp_dir() . '/mysql-private-' . bin2hex(random_bytes(6));
mkdir($fixture);
mkdir($fixture . '/www');
$privateFile = $fixture . '/mysql.php';
file_put_contents($privateFile, "<?php return ['host'=>'fixture.local','database'=>'fixture','user'=>'fixture','password'=>'synthetic'];");
try {
    $private = mysqlPrivateSettings($privateFile, $fixture . '/www');
    if ($private['host'] !== 'fixture.local') throw new RuntimeException('Private configuration failed.');
    try {
        mysqlPrivateSettings($privateFile, $fixture);
        throw new LogicException('A publicly accessible configuration was accepted.');
    } catch (RuntimeException $e) {
        // Expected: the private configuration must be outside the document root.
    }
    // Run with an empty environment to verify the external-webhost path.
    if (getenv('MYSQL_HOST') === false && getenv('MYSQL_PASSWORD') === false) {
        $_SERVER['DOCUMENT_ROOT'] = $fixture . '/www';
        putenv('MYSQL_CONFIG_FILE=' . $privateFile);
        $runtime = mysqlRuntimeSettings();
        if ($runtime['host'] !== 'fixture.local' || $runtime['password'] !== 'synthetic') {
            throw new RuntimeException('Private runtime configuration failed.');
        }
        putenv('MYSQL_HOST=override.local');
        $runtime = mysqlRuntimeSettings();
        if ($runtime['host'] !== 'override.local' || $runtime['database'] !== 'fixture') {
            throw new RuntimeException('Environment precedence failed.');
        }
        putenv('MYSQL_HOST');
        putenv('MYSQL_CONFIG_FILE');
        echo "PASS external-webhost runtime fallback and environment precedence.\n";
    }
    echo "PASS private host configuration and public-root rejection.\n";
} finally {
    unlink($privateFile);
    rmdir($fixture . '/www');
    rmdir($fixture);
}
