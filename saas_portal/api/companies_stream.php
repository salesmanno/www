<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
require_once __DIR__.'/../../includes/CompanyModuleAccess.php';
// Never hold the PHP session lock while an event stream remains open.
session_write_close();

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
                0 as module_count
            FROM tenant_companies tc
            ORDER BY tc.created_at DESC
        ");
        
        $companies=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($companies as &$company) $company['module_count']=count(CompanyModuleAccess::modules($db,(string)$company['id']));
        unset($company);
        return $companies;
    } catch (Exception $e) {
        error_log("SSE error: " . $e->getMessage());
        return [];
    }
}

$lastData = '';

while (true) {
    if (($_SESSION['saas_session_kind'] ?? '')==='global') {
        try {PortalSaasIdentity::active(getDBConnection(),(string)$_SESSION['saas_admin_id']);}
        catch (Throwable $e) {break;}
    }
    $companies = getCompaniesData();
    $currentData = json_encode($companies);
    
    if ($currentData !== $lastData) {
        echo "data: " . $currentData . "\n\n";
        if (ob_get_level()>0) ob_flush();
        flush();
        $lastData = $currentData;
    }
    
    // PHP's single-worker development server cannot serve a second request
    // while streaming forever. EventSource reconnects; serve one snapshot.
    if (PHP_SAPI==='cli-server') break;
    sleep(2);
    
    if (connection_aborted()) {
        break;
    }
}
