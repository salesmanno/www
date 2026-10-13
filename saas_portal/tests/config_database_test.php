<?php
// Only synthetic credentials and a disposable /tmp MySQL fixture are permitted.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$socket=$argv[1] ?? '';
if (!preg_match('#^/tmp/[^;]+/mysql\.sock$#D',$socket) || !file_exists($socket) ||
    ini_get('pdo_mysql.default_socket')!==$socket || getenv('MYSQL_HOST')!=='localhost' ||
    getenv('MYSQL_USER')!=='root' || getenv('MYSQL_PASSWORD')!=='') {
    throw new RuntimeException('Supply only the isolated fixture configuration.');
}
$db=new PDO('mysql:unix_socket='.$socket,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='config_test_'.bin2hex(random_bytes(6));
$privateFile = null;
try {
    $db->exec("CREATE DATABASE `{$schema}`");
    putenv('MYSQL_DATABASE='.$schema);
    if (($argv[2] ?? '') === 'private-file') {
        $privateFile = dirname($socket) . '/.saas-database.json';
        file_put_contents($privateFile, json_encode([
            'host' => 'mysql://localhost/', 'database' => $schema,
            'user' => 'root', 'password' => '', 'port' => 3306,
        ]));
        foreach (['MYSQL_HOST', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD'] as $key) putenv($key);
        putenv('MYSQL_CONFIG_FILE=' . $privateFile);
    }
    require __DIR__.'/../../config/database.php';
    $connection=getDBConnection();
    if ($connection->query('SELECT DATABASE()')->fetchColumn()!==$schema ||
        getDBConnection()!==$connection || getDBType()!=='mysql' ||
        $connection->getAttribute(PDO::ATTR_EMULATE_PREPARES)) {
        throw new RuntimeException('The configured MySQL connection was not used.');
    }
    $connection->exec('CREATE TABLE saas_staff (id VARCHAR(36) PRIMARY KEY, first_name VARCHAR(100), last_name VARCHAR(100))');
    $connection->exec('CREATE TABLE saas_admins (id VARCHAR(36) PRIMARY KEY, email VARCHAR(100), password_hash VARCHAR(255), role VARCHAR(30), is_active INT, saas_staff_id VARCHAR(36), last_login DATETIME NULL)');
    $connection->prepare('INSERT INTO saas_admins (id,email,password_hash,role,is_active) VALUES (?,?,?,?,1)')
        ->execute(['fixture-admin', 'fixture@example.test', password_hash('synthetic-login-password', PASSWORD_DEFAULT), 'admin']);
    $sessionId = 'fixture' . bin2hex(random_bytes(12));
    $code = 'ini_set("session.save_path",' . var_export(dirname($socket), true) . ');'
        . 'session_id(' . var_export($sessionId, true) . ');'
        . '$_SERVER["REQUEST_METHOD"]="POST";'
        . '$_POST=["email"=>"fixture@example.test","password"=>"synthetic-login-password"];'
        . 'require ' . var_export(realpath(__DIR__ . '/../auth/authenticate.php'), true) . ';';
    $process = proc_open([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . $socket, '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start isolated authentication check.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $stdout !== '' || $stderr !== '') {
        throw new RuntimeException('Isolated authentication process failed.');
    }
    // Authentication regenerates the session ID. Locate only this fixture's
    // resulting session, without reading any real application sessions.
    $sessionFiles = glob(dirname($socket) . '/sess_*');
    if (count($sessionFiles) !== 1) throw new RuntimeException('Fixture session was not created.');
    ini_set('session.save_path', dirname($socket));
    session_id(substr(basename($sessionFiles[0]), 5));
    session_start();
    $authenticated = ($_SESSION['saas_admin_id'] ?? '') === 'fixture-admin'
        && ($_SESSION['saas_session_kind'] ?? '') === 'ordinary';
    session_destroy();
    if (!$authenticated || !$connection->query("SELECT last_login FROM saas_admins WHERE id='fixture-admin'")->fetchColumn()) {
        throw new RuntimeException('Existing SaaS authentication did not complete.');
    }
    echo "PASS " . ($privateFile ? 'private file' : 'environment') . " MySQL configuration, isolated connection, caching and native prepares.\n";
    echo "PASS existing SaaS login and fixture session with synthetic credentials.\n";
} finally {
    if ($privateFile !== null && is_file($privateFile)) unlink($privateFile);
    $db->exec("DROP DATABASE IF EXISTS `{$schema}`");
}
