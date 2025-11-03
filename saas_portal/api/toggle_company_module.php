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
    $company_id = $data['company_id'] ?? '';
    $module_id = $data['module_id'] ?? '';
    $is_active = $data['is_active'] ?? false;
    
    $db = getDBConnection();
    
    if ($is_active) {
        $stmt = $db->prepare("
            INSERT INTO tenant_company_modules (company_id, module_id, is_active, granted_at)
            VALUES (?, ?, " . dbBool(true) . ", NOW())
            ON DUPLICATE KEY UPDATE is_active = " . dbBool(true) . ", granted_at = NOW()
        ");
        $stmt->execute([$company_id, $module_id]);
    } else {
        $stmt = $db->prepare("
            UPDATE tenant_company_modules 
            SET is_active = " . dbBool(false) . "
            WHERE company_id = ? AND module_id = ?
        ");
        $stmt->execute([$company_id, $module_id]);
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("Toggle company module error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
