<?php
require_once __DIR__ . '/mysql_settings.php';

class MysqlConfigurationException extends RuntimeException {}

/**
 * Use exactly one configuration source. A partial environment must not
 * accidentally connect to the database named in a different source.
 */
function loadMysqlConfiguration(array $environment, string $projectRoot, string $documentRoot = ''): array {
    $keys = ['host' => 'MYSQL_HOST', 'database' => 'MYSQL_DATABASE', 'user' => 'MYSQL_USER',
        'password' => 'MYSQL_PASSWORD', 'port' => 'MYSQL_PORT'];
    $hasEnvironment = false;
    foreach (['MYSQL_HOST', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD'] as $key) {
        if (isset($environment[$key]) && $environment[$key] !== false) $hasEnvironment = true;
    }
    if ($hasEnvironment) {
        $settings = [];
        foreach ($keys as $name => $key) $settings[$name] = $environment[$key] ?? false;
        $settings['port'] = $settings['port'] === false ? '' : $settings['port'];
    } else {
        $root = @realpath($documentRoot) ?: @realpath($projectRoot);
        if ($root === false) throw new MysqlConfigurationException('Databaseoppsettet er ikke tilgjengelig.');
        $configuredPath = $environment['MYSQL_CONFIG_FILE'] ?? false;
        $path = $configuredPath !== false && $configuredPath !== ''
            ? $configuredPath : dirname($root) . '/.saas-database.json';
        $resolved = @realpath($path);
        if ($resolved === false || !@is_file($resolved) || !@is_readable($resolved)) {
            throw new MysqlConfigurationException('Databaseoppsettet mangler på serveren.');
        }
        // Refuse files inside either the application or the public document root,
        // including symlinks. JSON secrets must never be downloadable over HTTP.
        foreach ([$root, @realpath($projectRoot)] as $publicRoot) {
            if ($publicRoot !== false && ($resolved === $publicRoot ||
                str_starts_with($resolved, rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
                throw new MysqlConfigurationException('Databaseoppsettet må ligge utenfor webroten.');
            }
        }
        $contents = @file_get_contents($resolved);
        try {
            $settings = json_decode($contents === false ? '' : $contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MysqlConfigurationException('Databaseoppsettet er ugyldig.');
        }
        if (!is_array($settings)) throw new MysqlConfigurationException('Databaseoppsettet er ugyldig.');
    }
    foreach (['host', 'database', 'user', 'password'] as $key) {
        if (!isset($settings[$key]) || !is_string($settings[$key]) ||
            ($key !== 'password' && trim($settings[$key]) === '')) {
            throw new MysqlConfigurationException('Databaseoppsettet er ufullstendig.');
        }
    }
    if (isset($settings['port']) && !is_string($settings['port']) && !is_int($settings['port'])) {
        throw new MysqlConfigurationException('Databaseoppsettet er ugyldig.');
    }
    try {
        return mysqlConnectionSettings($settings);
    } catch (RuntimeException $e) {
        throw new MysqlConfigurationException('Databaseoppsettet er ugyldig.');
    }
}
