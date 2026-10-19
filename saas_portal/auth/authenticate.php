<?php
// Do not hold the old session lock during network/database work or password verification.
$loginStarted=microtime(true);$loginPrevious=$loginStarted;$loginTimings=[];
$loginStage=static function(string $name) use (&$loginPrevious,&$loginTimings): void {
    $now=microtime(true);$loginTimings[$name]=round(($now-$loginPrevious)*1000,1);$loginPrevious=$now;
};
register_shutdown_function(static function() use ($loginStarted,&$loginTimings): void {
    $total=round((microtime(true)-$loginStarted)*1000,1);
    if (!headers_sent()) {
        $parts=[];foreach ($loginTimings as $name=>$ms) $parts[]=$name.';dur='.$ms;
        $parts[]='total;dur='.$total;header('Server-Timing: '.implode(', ',$parts));
    }
    if ($total>1500) error_log('SaaS login timing: '.json_encode($loginTimings+['total'=>$total]));
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /saas_portal/login.php?error=invalid_request');
    exit;
}

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    header('Location: /saas_portal/login.php?error=invalid_credentials');
    exit;
}

try {
    require_once __DIR__.'/../services/PortalLoginFailure.php';
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__.'/../services/PortalSaasIdentity.php';
    require_once __DIR__.'/../services/PortalGlobalUsers.php';
    $db = getDBConnection();
    $loginStage('database');
    
    $stmt = $db->prepare("
        SELECT sa.*, ss.first_name, ss.last_name 
        FROM saas_admins sa
        LEFT JOIN saas_staff ss ON sa.saas_staff_id = ss.id
        WHERE sa.email = ? AND sa.is_active = " . dbBool(true) . "
    ");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();
    $loginStage('lookup');
    
    if (!$admin) {
        header('Location: /saas_portal/login.php?error=invalid_credentials');
        exit;
    }
    
    if (!password_verify($password, $admin['password_hash'])) {
        header('Location: /saas_portal/login.php?error=invalid_credentials');
        exit;
    }
    $loginStage('password');
    
    // Preserve the existing SaaS roles; conversion must not lock legacy operators out.
    if (!$admin['is_active']) {
        header('Location: /saas_portal/login.php?error=inactive');
        exit;
    }
    
    $stmt = $db->prepare("UPDATE saas_admins SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$admin['id']]);
    $loginStage('last_login');
    session_start();
    $loginStage('session_wait');
    session_regenerate_id(true);
    
    $_SESSION['saas_admin_id'] = $admin['id'];
    $_SESSION['saas_admin_email'] = $admin['email'];
    $_SESSION['saas_admin_role'] = $admin['role'];
    $_SESSION['saas_admin_view_only'] = (int)($admin['view_only'] ?? 0)===1;
    $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['saas_admin_name'] = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
    if ($_SESSION['saas_admin_name']==='') $_SESSION['saas_admin_name']=$admin['email'];
    $_SESSION['saas_logged_in_at'] = time();
    $trackedGlobal=$admin['role']==='global_admin' && PortalSaasIdentity::hasGlobalLink($db,(string)$admin['id']);
    $_SESSION['saas_session_kind']=$trackedGlobal?'global':'ordinary';
    if ($trackedGlobal) {
        $db->prepare('INSERT INTO saas_global_sessions(id,saas_admin_id,session_hash) VALUES (?,?,?)')
            ->execute([PortalGlobalUsers::uuid(),$admin['id'],hash('sha256',session_id())]);
        // A converted global signs in only as a SaaS identity, never as an
        // old tenant principal left over in the same browser session.
        foreach (array_keys($_SESSION) as $key) if (str_starts_with($key,'tenant_admin_')) unset($_SESSION[$key]);
    }
    
    $destination=$_SESSION['saas_admin_view_only'] ? 'prospects.php' : 'dashboard.php';
    $loginStage('session_setup');
    session_write_close();
    header('Location: /saas_portal/pages/'.$destination);
    exit;
    
} catch (Throwable $e) {
    foreach (array_keys($_SESSION ?? []) as $key) if (str_starts_with($key,'saas_')) unset($_SESSION[$key]);
    $cause = $e->getPrevious() ?: $e;
    $code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$cause->getCode());
    error_log('SaaS admin login failed: ' . get_class($cause) . ' code=' . $code);
    $failure = class_exists('PortalLoginFailure', false) ? PortalLoginFailure::code($e) : 'system_error';
    header('Location: /saas_portal/login.php?error=' . $failure);
    exit;
}
