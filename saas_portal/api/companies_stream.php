<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../../config/database.php';

function getCompaniesData() {
    try {
        $db = getDBConnection();
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
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("SSE error: " . $e->getMessage());
        return [];
    }
}

$lastData = '';

while (true) {
    $companies = getCompaniesData();
    $currentData = json_encode($companies);
    
    if ($currentData !== $lastData) {
        echo "data: " . $currentData . "\n\n";
        ob_flush();
        flush();
        $lastData = $currentData;
    }
    
    sleep(2);
    
    if (connection_aborted()) {
        break;
    }
}
