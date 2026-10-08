<?php
require_once __DIR__ . '/../services/PortalDatabase.php';

class DatabaseTestPDO extends PDO { public function __construct() {} }
$connectionMode = 'available';
function getDBConnection() {
    global $connectionMode;
    if ($connectionMode === 'exception') throw new RuntimeException('FAKE_PRIVATE_CONNECTION_DETAIL');
    if ($connectionMode === 'invalid') return false;
    return new DatabaseTestPDO();
}
function databaseCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$log = tempnam(sys_get_temp_dir(), 'portal-db-test-');
$previousLog = ini_get('error_log');
ini_set('error_log', $log);
try {
    foreach (['exception','invalid'] as $connectionMode) {
        try {
            PortalDatabase::connect();
            throw new LogicException('Unavailable connection accepted');
        } catch (PortalDatabaseUnavailableException $e) {
            databaseCheck(PortalDatabase::unavailable($e), 'Connection failure not classified');
            $_SERVER['REQUEST_METHOD'] = 'GET';
            [$status, $body] = PortalDatabase::failure($e,'company_seo','Generic fallback');
            databaseCheck($status === 503 && $body['error_code'] === 'database_unavailable', 'Wrong unavailable response');
            databaseCheck(preg_match('/^[a-f0-9]{12}$/D',$body['request_id']) === 1, 'Missing safe reference');
            databaseCheck(!str_contains(json_encode($body),'FAKE_PRIVATE'), 'Connection details leaked');
        }
    }
    $connectionMode = 'available';
    databaseCheck(PortalDatabase::connect() instanceof PDO, 'Recovery failed');
    foreach ([1040,1203,2002,2003,2005,2006,2013,2055] as $driver) {
        $e = new PDOException('FAKE_SQL_DETAIL');
        $e->errorInfo = ['HY000',$driver,'FAKE_SQL_DETAIL'];
        databaseCheck(PortalDatabase::unavailable($e), 'Lost connection not classified');
    }
    $e = new PDOException('FAKE_SCHEMA_DETAIL');
    $e->errorInfo = ['42S22',1054,'FAKE_SCHEMA_DETAIL'];
    databaseCheck(!PortalDatabase::unavailable($e), 'Schema error disguised as connection failure');
    [$status,$body] = PortalDatabase::failure($e,'company_seo','Generic SEO error','seo_unavailable');
    databaseCheck($status === 500 && $body['error_code'] === 'seo_unavailable','Schema failure lost distinction');
    $e = new PDOException('FAKE_SQL_DETAIL');
    $e->errorInfo = ['HY000',2013,'FAKE_SQL_DETAIL'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$status,$body] = PortalDatabase::failure($e,'company_seo','Generic fallback');
    databaseCheck($status === 503 && !str_contains($body['message'],'Ingen endringer'), 'Unknown commit outcome falsely claimed rollback');
    $content = file_get_contents($log);
    foreach (['FAKE_PRIVATE','FAKE_SQL','FAKE_SCHEMA'] as $marker) {
        databaseCheck(!str_contains($content,$marker),'Sensitive exception message logged');
    }
    databaseCheck(str_contains($content,$body['request_id']),'Response reference missing from log');
    echo "PASS connection classification, unavailable/invalid PDO, recovery, query disconnects, schema distinction, safe references and uncertain writes.\n";
} finally {
    ini_set('error_log',$previousLog);
    unlink($log);
}
