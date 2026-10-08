<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__.'/../includes/CompanyModuleAccess.php';

header('Content-Type: application/json');

if (!isset($_SESSION['tenant_admin_id']) || !isset($_SESSION['company_id'])) {
    echo json_encode(['success' => false, 'message' => 'Ikke autentisert']);
    exit;
}

$company_id = $_SESSION['company_id'];

try {
    $db = getDBConnection();
    
    // Get all admins for this company with their linked staff info
    $stmt = $db->prepare("
        SELECT 
            ta.id as admin_id,
            ta.username,
            ta.is_active,
            ts.staff_id,
            ts.first_name,
            ts.last_name,
            ts.email,
            ts.position
        FROM tenant_admins ta
        LEFT JOIN tenant_staff ts ON ta.tenant_staff_id = ts.id
        WHERE ta.company_id = ?
        ORDER BY ta.username
    ");
    $stmt->execute([$company_id]);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all available modules
    $modules = CompanyModuleAccess::modules($db,(string)$company_id);
    
    // For each admin, get their module access
    foreach ($admins as &$admin) {
        $stmt = $db->prepare("
            SELECT module_id, is_active
            FROM tenant_admin_modules
            WHERE tenant_admin_id = ?
        ");
        $stmt->execute([$admin['admin_id']]);
        $adminModules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create a map of module_id => is_active
        $moduleAccess = [];
        foreach ($adminModules as $am) {
            $moduleAccess[$am['module_id']] = $am['is_active'];
        }
        
        $admin['modules'] = $moduleAccess;
    }
    
    echo json_encode([
        'success' => true,
        'admins' => $admins,
        'available_modules' => $modules
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching staff modules: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved henting av data: ' . $e->getMessage()
    ]);
}
