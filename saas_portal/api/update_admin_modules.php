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
    $module_ids = $data['module_ids'] ?? [];
    
    $db = getDBConnection();
    $db->beginTransaction();
    
    $stmt = $db->prepare("DELETE FROM tenant_admin_modules WHERE tenant_admin_id = ?");
    $stmt->execute([$admin_id]);
    
    $insertStmt = $db->prepare("
        INSERT INTO tenant_admin_modules (tenant_admin_id, module_id, is_active, granted_by, granted_at)
        VALUES (?, ?, " . dbBool(true) . ", ?, NOW())
    ");
    
    foreach ($module_ids as $module_id) {
        $insertStmt->execute([$admin_id, $module_id, $_SESSION['saas_admin_id']]);
    }
    
    $db->commit();
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Update admin modules error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
