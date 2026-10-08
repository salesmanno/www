<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['tenant_admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Ikke autentisert']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__.'/../includes/CompanyModuleAccess.php';

$input = json_decode(file_get_contents('php://input'), true);
$moduleIds = $input['modules'] ?? [];

try {
    $db = getDBConnection();
    
    if (!$db) {
        echo json_encode(['success' => false, 'error' => 'Database ikke tilgjengelig']);
        exit;
    }
    
    $adminId = $_SESSION['tenant_admin_id'];
    $companyId = $_SESSION['company_id'];
    if (!is_array($moduleIds)||!array_is_list($moduleIds)||count($moduleIds)>200||
        array_filter($moduleIds,static fn($id)=>!is_string($id)||$id===''||strlen($id)>36)) throw new InvalidArgumentException('Ugyldige moduler valgt.');
    
    $allowedModules = array_column(CompanyModuleAccess::modules($db,(string)$companyId),'id');
    
    $validModuleIds = array_intersect($moduleIds, $allowedModules);
    
    if (count($validModuleIds) != count($moduleIds)) {
        error_log("Attempted privilege escalation by admin $adminId: " . json_encode(array_diff($moduleIds, $allowedModules)));
        echo json_encode(['success' => false, 'error' => 'Ugyldige moduler valgt']);
        exit;
    }
    
    $db->beginTransaction();
    $companyLock=$db->prepare('SELECT id FROM tenant_companies WHERE id=? FOR UPDATE');
    $companyLock->execute([$companyId]);
    $actor=$db->prepare('SELECT id FROM tenant_admins WHERE id=? AND company_id=? AND is_active=TRUE');
    $actor->execute([$adminId,$companyId]);
    if (!$actor->fetchColumn()) throw new InvalidArgumentException('Firmasesjonen er avsluttet. Logg inn på nytt.');
    $allowedModules=array_column(CompanyModuleAccess::modules($db,(string)$companyId),'id');
    if (array_diff($moduleIds,$allowedModules)) throw new InvalidArgumentException('Bedriftens modultilgang er endret. Last siden på nytt.');
    
    // Hent system saas_admin ID (for granted_by kolonnen)
    $stmt = $db->query("SELECT id FROM saas_admins LIMIT 1");
    $saasAdminId = $stmt->fetchColumn();
    
    $stmt = $db->prepare("DELETE FROM tenant_admin_modules WHERE tenant_admin_id = ?");
    $stmt->execute([$adminId]);
    
    if (!empty($validModuleIds)) {
        $stmt = $db->prepare("
            INSERT INTO tenant_admin_modules (tenant_admin_id, module_id, granted_by) 
            VALUES (?, ?, ?)
        ");
        
        foreach ($validModuleIds as $moduleId) {
            $stmt->execute([$adminId, $moduleId, $saasAdminId]);
        }
    }
    
    $db->commit();
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    error_log("Permission update error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database feil']);
}
