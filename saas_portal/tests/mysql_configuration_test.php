<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../../config/mysql_configuration.php';
$directory = sys_get_temp_dir() . '/saas-config-' . bin2hex(random_bytes(6));
mkdir($directory);
mkdir($directory . '/www');
$file = $directory . '/.saas-database.json';
$fixture = ['host' => 'https://db.example.test/', 'database' => 'fixture',
    'user' => 'fixture', 'password' => 'synthetic-secret', 'port' => 3307];
$check = function ($condition) { if (!$condition) throw new RuntimeException('Configuration assertion failed.'); };
$reject = function (callable $fn) use ($check) {
    try { $fn(); } catch (MysqlConfigurationException $e) {
        $check(!str_contains($e->getMessage(), 'synthetic-secret'));
        return;
    }
    throw new RuntimeException('Invalid configuration was accepted.');
};
try {
    file_put_contents($file, json_encode($fixture));
    $result = loadMysqlConfiguration([], $directory . '/www', $directory . '/www');
    $check($result['host'] === 'db.example.test' && $result['port'] === '3307');
    $check($result['password'] === 'synthetic-secret');
    $environment = ['MYSQL_HOST' => 'env.example.test', 'MYSQL_DATABASE' => 'env_fixture',
        'MYSQL_USER' => 'env_fixture', 'MYSQL_PASSWORD' => '', 'MYSQL_CONFIG_FILE' => $file];
    $result = loadMysqlConfiguration($environment, $directory . '/www');
    $check($result['host'] === 'env.example.test' && $result['password'] === '');
    $reject(fn() => loadMysqlConfiguration(['MYSQL_HOST' => 'env.example.test'], $directory . '/www'));
    file_put_contents($directory . '/www/private.json', json_encode($fixture));
    $reject(fn() => loadMysqlConfiguration(['MYSQL_CONFIG_FILE' => $directory . '/www/private.json'], $directory . '/www'));
    symlink($directory . '/www/private.json', $directory . '/alias.json');
    $reject(fn() => loadMysqlConfiguration(['MYSQL_CONFIG_FILE' => $directory . '/alias.json'], $directory . '/www'));
    file_put_contents($file, '{"password":"synthetic-secret", broken}');
    $reject(fn() => loadMysqlConfiguration([], $directory . '/www'));
    file_put_contents($file, json_encode($fixture + ['unused' => 'allowed']));
    unset($fixture['password']);
    file_put_contents($file, json_encode($fixture));
    $reject(fn() => loadMysqlConfiguration([], $directory . '/www'));
    unlink($file);
    $reject(fn() => loadMysqlConfiguration([], $directory . '/www'));
    echo "PASS private file, environment priority, empty password, partial settings, missing file, malformed JSON, webroot and symlink rejection.\n";
} finally {
    foreach ([$file, $directory . '/alias.json', $directory . '/www/private.json'] as $path) {
        if (is_file($path) || is_link($path)) unlink($path);
    }
    rmdir($directory . '/www');
    rmdir($directory);
}
