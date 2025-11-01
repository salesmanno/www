<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../admin.php?error=invalid_request');
    exit;
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    header('Location: ../admin.php?error=invalid_credentials');
    exit;
}

try {
    $db = getDBConnection();
    if (!$db) {
        header('Location: ../admin.php?error=database_unavailable');
        exit;
    }
    
    $admin = getTenantAdminByUsername($username);
    
    if (!$admin) {
        header('Location: ../admin.php?error=invalid_credentials');
        exit;
    }
    
    if (!verifyPassword($password, $admin['password_hash'])) {
        header('Location: ../admin.php?error=invalid_credentials');
        exit;
    }
    
    if (!$admin['is_active']) {
        header('Location: ../admin.php?error=account_inactive');
        exit;
    }
    
    if (!$admin['company_active']) {
        header('Location: ../admin.php?error=company_inactive');
        exit;
    }
    
    // SINGLE SESSION ENFORCEMENT: Check if user has an active session with recent activity (within 30 min)
    // First, clean up stale sessions (inactive for more than 30 minutes)
    $stmt = $db->prepare("
        UPDATE tenant_admin_sessions 
        SET is_active = " . dbBool(false) . ", 
            logout_at = NOW()
        WHERE tenant_admin_id = ? 
        AND is_active = " . dbBool(true) . "
        AND last_activity < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $stmt->execute([$admin['id']]);
    
    // Now check for truly active sessions
    $stmt = $db->prepare("
        SELECT COUNT(*) as active_sessions 
        FROM tenant_admin_sessions 
        WHERE tenant_admin_id = ? 
        AND is_active = " . dbBool(true) . "
        AND last_activity >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $stmt->execute([$admin['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['active_sessions'] > 0) {
        header('Location: ../admin.php?error=already_logged_in');
        exit;
    }
    
    updateTenantAdminLastLogin($admin['id']);
    
    session_regenerate_id(true);
    
    $_SESSION['tenant_admin_id'] = $admin['id'];
    $_SESSION['company_id'] = $admin['company_id'];
    $_SESSION['admin_email'] = $admin['staff_email'] ?? '';
    $_SESSION['admin_first_name'] = $admin['first_name'] ?? '';
    $_SESSION['db_type'] = getDBType();
    $_SESSION['admin_last_name'] = $admin['last_name'] ?? '';
    $_SESSION['admin_name'] = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
    $_SESSION['company_name'] = $admin['company_name'];
    $_SESSION['subdomain'] = $admin['subdomain'];
    $_SESSION['logged_in_at'] = time();
    $_SESSION['session_id'] = session_id();
    
    createTenantAdminSession($admin['id'], session_id());
    
    header('Location: ../pages/company_dashboard.php');
    exit;
    
} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    header('Location: ../admin.php?error=system_error');
    exit;
}
