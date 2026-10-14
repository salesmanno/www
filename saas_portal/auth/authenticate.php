<?php
session_start();

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
    
    $stmt = $db->prepare("
        SELECT sa.*, ss.first_name, ss.last_name 
        FROM saas_admins sa
        LEFT JOIN saas_staff ss ON sa.saas_staff_id = ss.id
        WHERE sa.email = ? AND sa.is_active = " . dbBool(true) . "
    ");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();
    
    if (!$admin) {
        header('Location: /saas_portal/login.php?error=invalid_credentials');
        exit;
    }
    
    if (!password_verify($password, $admin['password_hash'])) {
        header('Location: /saas_portal/login.php?error=invalid_credentials');
        exit;
    }
    
    // Preserve the existing SaaS roles; conversion must not lock legacy operators out.
    if (!$admin['is_active']) {
        header('Location: /saas_portal/login.php?error=inactive');
        exit;
    }
    
    $stmt = $db->prepare("UPDATE saas_admins SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$admin['id']]);
    
    session_regenerate_id(true);
    
    $_SESSION['saas_admin_id'] = $admin['id'];
    $_SESSION['saas_admin_email'] = $admin['email'];
    $_SESSION['saas_admin_role'] = $admin['role'];
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
    
    header('Location: /saas_portal/pages/dashboard.php');
    exit;
    
} catch (Throwable $e) {
    foreach (array_keys($_SESSION) as $key) if (str_starts_with($key,'saas_')) unset($_SESSION[$key]);
    $cause = $e->getPrevious() ?: $e;
    $code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$cause->getCode());
    error_log('SaaS admin login failed: ' . get_class($cause) . ' code=' . $code);
    $failure = class_exists('PortalLoginFailure', false) ? PortalLoginFailure::code($e) : 'system_error';
    header('Location: /saas_portal/login.php?error=' . $failure);
    exit;
}
