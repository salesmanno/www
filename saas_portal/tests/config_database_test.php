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
try {
    $db->exec("CREATE DATABASE `{$schema}`");
    putenv('MYSQL_DATABASE='.$schema);
    require __DIR__.'/../../config/database.php';
    $connection=getDBConnection();
    if ($connection->query('SELECT DATABASE()')->fetchColumn()!==$schema ||
        getDBConnection()!==$connection || getDBType()!=='mysql' ||
        $connection->getAttribute(PDO::ATTR_EMULATE_PREPARES)) {
        throw new RuntimeException('The configured MySQL connection was not used.');
    }
    echo "PASS existing MySQL configuration, isolated connection, caching and native prepares.\n";
} finally {
    $db->exec("DROP DATABASE IF EXISTS `{$schema}`");
}
