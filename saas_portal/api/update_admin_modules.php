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
    
    if (empty($admin_id)) {
        echo json_encode(['success' => false, 'message' => 'Admin ID mangler']);
        exit;
    }
    
    if (!is_array($module_ids)) {
        echo json_encode(['success' => false, 'message' => 'Ugyldig modul-data']);
        exit;
    }
    
    $db = getDBConnection();
    $db->beginTransaction();
    
    // Valider at admin tilhører et firma
    $stmt = $db->prepare("SELECT company_id FROM tenant_admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $company_id = $stmt->fetchColumn();
    
    if (!$company_id) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Admin ikke funnet']);
        exit;
    }
    
    // Valider at alle modul-IDer tilhører firmaet
    if (!empty($module_ids)) {
        $placeholders = str_repeat('?,', count($module_ids) - 1) . '?';
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM tenant_company_modules 
            WHERE company_id = ? AND module_id IN ($placeholders) AND is_active = " . dbBool(true)
        );
        $stmt->execute(array_merge([$company_id], $module_ids));
        $validCount = $stmt->fetchColumn();
        
        if ($validCount != count($module_ids)) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'En eller flere moduler tilhører ikke firmaet']);
            exit;
        }
    }
    
    // Slett eksisterende moduler
    $stmt = $db->prepare("DELETE FROM tenant_admin_modules WHERE tenant_admin_id = ?");
    $stmt->execute([$admin_id]);
    
    // Sett inn nye moduler
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
