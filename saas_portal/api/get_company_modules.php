<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$company_id = $_GET['company_id'] ?? '';

try {
    $db = getDBConnection();
    
    $stmt = $db->query("SELECT id, name, description, module_key FROM system_modules WHERE is_active = " . dbBool(true));
    $allModules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("SELECT module_id FROM tenant_company_modules WHERE company_id = ? AND is_active = " . dbBool(true));
    $stmt->execute([$company_id]);
    $grantedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($allModules as &$module) {
        $module['granted'] = in_array($module['id'], $grantedIds);
    }
    
    echo json_encode(['success' => true, 'modules' => $allModules]);
    
} catch (Exception $e) {
    error_log("Get company modules error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
