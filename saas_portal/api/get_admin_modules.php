<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
require_once __DIR__ . '/../../includes/CompanyModuleAccess.php';

header('Content-Type: application/json');

$admin_id = $_GET['admin_id'] ?? '';
$company_id = $_GET['company_id'] ?? '';

try {
    $db = getDBConnection();
    
    $owner = $db->prepare('SELECT id FROM tenant_admins WHERE id=? AND company_id=?');
    $owner->execute([$admin_id,$company_id]);
    if (!$owner->fetchColumn()) throw new InvalidArgumentException('Brukeren tilhører ikke bedriften.');
    $companyModules = CompanyModuleAccess::modules($db, (string)$company_id);
    
    $stmt = $db->prepare("SELECT module_id FROM tenant_admin_modules WHERE tenant_admin_id = ?");
    $stmt->execute([$admin_id]);
    $adminModuleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($companyModules as &$module) {
        $module['has_access'] = in_array($module['id'], $adminModuleIds);
    }
    
    echo json_encode(['success' => true, 'modules' => $companyModules]);
    
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Get admin modules failed.');
    echo json_encode(['success' => false, 'message' => 'Kunne ikke hente brukerens modultilgang.']);
}
