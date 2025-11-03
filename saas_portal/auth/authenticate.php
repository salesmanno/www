<?php
session_start();

require_once __DIR__ . '/../../config/database.php';

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
    $_SESSION['saas_logged_in_at'] = time();
    
    header('Location: /saas_portal/pages/dashboard.php');
    exit;
    
} catch (Exception $e) {
    error_log("SaaS admin login error: " . $e->getMessage());
    header('Location: /saas_portal/login.php?error=system_error');
    exit;
}
