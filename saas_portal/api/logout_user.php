<?php
session_start();

// Only SaaS admins can log out other users
if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $admin_id = $data['admin_id'] ?? '';
    
    if (empty($admin_id)) {
        echo json_encode(['success' => false, 'message' => 'Admin ID mangler']);
        exit;
    }
    
    $db = getDBConnection();
    
    // Mark admin sessions as inactive
    $stmt = $db->prepare("
        UPDATE tenant_admin_sessions 
        SET is_active = 0, logout_at = NOW()
        WHERE tenant_admin_id = ?
    ");
    $stmt->execute([$admin_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Administrator logget ut'
    ]);
    
} catch (Exception $e) {
    error_log("Logout user error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved utlogging: ' . $e->getMessage()
    ]);
}
