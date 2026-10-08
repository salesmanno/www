<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/PortalDatabase.php';

try {
    $db = PortalDatabase::connect();
    $logoColumn = $db->query("SHOW COLUMNS FROM tenant_companies LIKE 'logo_url'")->fetch()
        ? 'tc.logo_url' : 'NULL AS logo_url';
    
    $stmt = $db->query("
        SELECT 
            tc.id,
            tc.name,
            {$logoColumn},
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
    
} catch (Throwable $e) {
    [$status, $body] = PortalDatabase::failure($e, 'company_list', 'Kunne ikke hente firmalisten. Prøv igjen.');
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
}
