<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$admin_id = $_GET['admin_id'] ?? '';
$company_id = $_GET['company_id'] ?? '';

try {
    $db = getDBConnection();
    
    $stmt = $db->prepare("
        SELECT sm.id, sm.name
        FROM system_modules sm
        INNER JOIN tenant_company_modules tcm ON sm.id = tcm.module_id
        WHERE tcm.company_id = ? AND tcm.is_active = " . dbBool(true) . "
    ");
    $stmt->execute([$company_id]);
    $companyModules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("SELECT module_id FROM tenant_admin_modules WHERE tenant_admin_id = ? AND is_active = " . dbBool(true));
    $stmt->execute([$admin_id]);
    $adminModuleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($companyModules as &$module) {
        $module['has_access'] = in_array($module['id'], $adminModuleIds);
    }
    
    echo json_encode(['success' => true, 'modules' => $companyModules]);
    
} catch (Exception $e) {
    error_log("Get admin modules error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
