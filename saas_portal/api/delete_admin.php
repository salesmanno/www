<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $admin_id = $data['admin_id'] ?? '';
    
    $db = getDBConnection();
    
    $stmt = $db->prepare("DELETE FROM tenant_admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("Delete admin error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
