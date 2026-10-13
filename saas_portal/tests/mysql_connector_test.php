<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../../config/mysql_connector.php';
class ConnectorFixturePdo extends PDO { public function __construct() {} }
$fixture = new ConnectorFixturePdo();
$settings = ['host' => 'db.example.test', 'port' => '3306', 'database' => 'fixture',
    'user' => 'fixture', 'password' => 'synthetic'];
$attempts = [];
$resolutions = 0;
$resolve = function ($host) use (&$resolutions): array {
    if ($host !== 'db.example.test') throw new RuntimeException('Wrong host resolved.');
    $resolutions++;
    return ['not-an-address', '192.0.2.1', '192.0.2.2'];
};
$connect = function ($host, $received) use (&$attempts, $settings, $fixture): PDO {
    if ($received !== $settings) throw new RuntimeException('Configuration changed during retry.');
    $attempts[] = $host;
    if ($host === 'db.example.test') throw new PDOException('Synthetic transport failure', 2002);
    return $fixture;
};
if (connectConfiguredMysql($settings, $connect, $resolve) !== $fixture ||
    $attempts !== ['db.example.test', '192.0.2.1'] || $resolutions !== 1) {
    throw new RuntimeException('Same-host IPv4 recovery failed.');
}
foreach ([1045, 1049, 2006] as $code) {
    $calls = 0;
    try {
        connectConfiguredMysql($settings, function () use (&$calls, $code): PDO {
            $calls++;
            throw new PDOException('Synthetic non-connect failure', $code);
        }, $resolve);
        throw new RuntimeException('Expected failure.');
    } catch (PDOException $e) {
        if ($calls !== 1 || (int)$e->getCode() !== $code) throw new RuntimeException('Unsafe retry.');
    }
}
foreach (['localhost', '192.0.2.5', '2001:db8::1'] as $host) {
    $calls = 0;
    try {
        connectConfiguredMysql(array_replace($settings, ['host' => $host]), function () use (&$calls): PDO {
            $calls++;
            throw new PDOException('Synthetic transport failure', 2002);
        }, function (): array { throw new RuntimeException('Should not resolve socket or literal IP.'); });
    } catch (PDOException $e) {
        if ($calls !== 1) throw new RuntimeException('Literal host was retried.');
    }
}
$calls = 0;
try {
    connectConfiguredMysql($settings, function () use (&$calls): PDO {
        $calls++;
        $e = new PDOException('Synthetic transport failure');
        $e->errorInfo = ['HY000', 2002, 'Synthetic'];
        throw $e;
    }, $resolve);
} catch (PDOException $e) {
    if ($calls !== 2) throw new RuntimeException('Connection attempts were not bounded.');
}
$resolutionsBefore = $resolutions;
if (connectConfiguredMysql($settings, fn() => $fixture, $resolve) !== $fixture ||
    $resolutions !== $resolutionsBefore) throw new RuntimeException('Healthy connection changed.');
echo "PASS same-host IPv4 recovery, bounded attempts, healthy connection, no authentication/schema/query retries or socket/IP fallback.\n";
