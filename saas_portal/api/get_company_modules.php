<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
require_once __DIR__.'/../../includes/CompanyModuleAccess.php';

header('Content-Type: application/json');

$company_id = $_GET['company_id'] ?? '';

try {
    $db = getDBConnection();
    
    $stmt = $db->query("SELECT id, name, description, module_key FROM system_modules WHERE is_active = " . dbBool(true));
    $allModules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $grantedIds = array_column(CompanyModuleAccess::modules($db,(string)$company_id),'id');
    
    foreach ($allModules as &$module) {
        $module['granted'] = in_array($module['id'], $grantedIds);
    }
    
    echo json_encode(['success' => true, 'modules' => $allModules]);
    
} catch (Exception $e) {
    error_log('Get company modules failed.');
    echo json_encode(['success' => false, 'message' => 'Kunne ikke hente bedriftens modultilgang.']);
}
