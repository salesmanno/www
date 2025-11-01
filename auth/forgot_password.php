<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../admin.php?error=invalid_request');
    exit;
}

$email = $_POST['email'] ?? '';
$secret = $_POST['secret'] ?? '';

// Always return the same generic message for security
$generic_message = 'reset_request_sent';

if (empty($email) || empty($secret)) {
    // Don't reveal that fields are missing
    header('Location: ../admin.php?message=' . $generic_message);
    exit;
}

try {
    $db = getDBConnection();
    
    if ($db === null) {
        error_log("Forgot password: Database connection failed");
        header('Location: ../admin.php?message=' . $generic_message);
        exit;
    }
    
    // Check if email and mfa_secret match
    $stmt = $db->prepare("
        SELECT id, email, mfa_secret 
        FROM tenant_admins 
        WHERE email = ? AND mfa_secret = ? AND is_active = TRUE
        LIMIT 1
    ");
    $stmt->execute([$email, $secret]);
    $admin = $stmt->fetch();
    
    // If match found, update password to "bergsoy"
    if ($admin) {
        $newPasswordHash = hashPassword('bergsoy');
        
        $updateStmt = $db->prepare("
            UPDATE tenant_admins 
            SET password_hash = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$newPasswordHash, $admin['id']]);
        
        error_log("Password reset successful for admin ID: " . $admin['id']);
    } else {
        // Log failed attempt but don't reveal it to user
        error_log("Forgot password: Invalid email or secret provided");
    }
    
    // Always return the same generic message regardless of success/failure
    header('Location: ../admin.php?message=' . $generic_message);
    exit;
    
} catch (Exception $e) {
    error_log("Forgot password error: " . $e->getMessage());
    // Still return generic message even on error
    header('Location: ../admin.php?message=' . $generic_message);
    exit;
}
