<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__.'/../includes/CompanyModuleAccess.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Kun POST tillatt']);
    exit;
}

if (!isset($_SESSION['tenant_admin_id']) || !isset($_SESSION['company_id'])) {
    echo json_encode(['success' => false, 'message' => 'Ikke autentisert']);
    exit;
}

$admin_id = $_SESSION['tenant_admin_id'];
$company_id = $_SESSION['company_id'];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $target_admin_id = $data['admin_id'] ?? '';
    $modules = $data['modules'] ?? []; // Array of module_ids that should be active
    
    if (!$target_admin_id) {
        echo json_encode(['success' => false, 'message' => 'Admin-ID mangler']);
        exit;
    }
    
    $db = getDBConnection();
    $db->beginTransaction();
    $companyLock=$db->prepare('SELECT id FROM tenant_companies WHERE id=? FOR UPDATE');
    $companyLock->execute([$company_id]);
    $actor=$db->prepare('SELECT id FROM tenant_admins WHERE id=? AND company_id=? AND is_active=TRUE');
    $actor->execute([$admin_id,$company_id]);
    if (!$actor->fetchColumn()) throw new InvalidArgumentException('Firmasesjonen er avsluttet. Logg inn på nytt.');
    
    // Verify that target admin belongs to this company
    $stmt = $db->prepare("SELECT id FROM tenant_admins WHERE id = ? AND company_id = ?");
    $stmt->execute([$target_admin_id, $company_id]);
    if (!$stmt->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Ugyldig admin']);
        exit;
    }
    
    // Get all available modules
    if (!is_array($modules)||!array_is_list($modules)||count($modules)>200||array_filter($modules,static fn($id)=>!is_string($id)||$id===''||strlen($id)>36)) throw new InvalidArgumentException('Ugyldig modulvalg.');
    $availableModules = array_column(CompanyModuleAccess::modules($db,(string)$company_id),'id');
    if (array_diff($modules,$availableModules)) throw new InvalidArgumentException('En valgt modul er ikke tilgjengelig på bedriftens plan.');
    
    // Delete existing module assignments for this admin
    $stmt = $db->prepare("DELETE FROM tenant_admin_modules WHERE tenant_admin_id = ?");
    $stmt->execute([$target_admin_id]);
    
    // Insert new module assignments
    $insertStmt = $db->prepare("
        INSERT INTO tenant_admin_modules 
        (tenant_admin_id, module_id, is_active, granted_by, granted_at)
        VALUES (?, ?, TRUE, ?, NOW())
    ");
    
    foreach ($modules as $module_id) {
        $module_id = (string)$module_id;
        
        // Verify module exists
        if (!in_array($module_id, $availableModules)) {
            continue;
        }
        
        $insertStmt->execute([$target_admin_id, $module_id, $admin_id]);
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Modulrettigheter oppdatert',
        'modules_granted' => count($modules)
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error updating staff modules: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved oppdatering: ' . $e->getMessage()
    ]);
}
