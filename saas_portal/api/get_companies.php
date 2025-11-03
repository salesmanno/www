<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';

try {
    $db = getDBConnection();
    
    $stmt = $db->query("
        SELECT 
            tc.id,
            tc.name,
            tc.subdomain,
            tc.contact_email,
            tc.contact_phone,
            tc.is_active,
            tc.created_at,
            (SELECT COUNT(*) FROM tenant_admins WHERE company_id = tc.id) as admin_count,
            (SELECT COUNT(*) FROM tenant_staff WHERE company_id = tc.id) as staff_count,
            (SELECT COUNT(*) FROM tenant_company_modules WHERE company_id = tc.id AND is_active = " . dbBool(true) . ") as module_count
        FROM tenant_companies tc
        ORDER BY tc.created_at DESC
    ");
    
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'companies' => $companies]);
    
} catch (Exception $e) {
    error_log("Get companies error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Feil: ' . $e->getMessage()]);
}
