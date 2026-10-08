<?php
session_start();
if (isset($_SESSION['saas_admin_id'])) {
    try {
        require_once __DIR__.'/../../config/database.php';
        $db=getDBConnection();
        $db->prepare('UPDATE saas_global_sessions SET revoked_at=NOW() WHERE saas_admin_id=? AND session_hash=? AND revoked_at IS NULL')
            ->execute([$_SESSION['saas_admin_id'],hash('sha256',session_id())]);
    } catch(Throwable $e){error_log('SaaS logout: global session tracking unavailable.');}
}

$_SESSION = array();

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

session_destroy();

header('Location: /saas_portal/login.php');
exit;
