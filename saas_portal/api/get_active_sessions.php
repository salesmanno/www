<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    $db = getDBConnection();
    
    // Hent aktive sessions (aktive siste 30 minutter)
    $stmt = $db->prepare("
        SELECT 
            tas.id as session_id,
            tas.tenant_admin_id,
            tas.login_at,
            tas.last_activity,
            tas.ip_address,
            tc.id as company_id,
            tc.name as company_name,
            tc.subdomain,
            ta.username,
            CONCAT(ts.first_name, ' ', ts.last_name) as admin_name,
            TIMESTAMPDIFF(MINUTE, tas.last_activity, NOW()) as minutes_idle
        FROM tenant_admin_sessions tas
        INNER JOIN tenant_admins ta ON tas.tenant_admin_id = ta.id
        INNER JOIN tenant_companies tc ON ta.company_id = tc.id
        LEFT JOIN tenant_staff ts ON ta.tenant_staff_id = ts.id
        WHERE tas.is_active = 1 
          AND tas.logout_at IS NULL
          AND tas.last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY tas.last_activity DESC
    ");
    
    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Grupper per bedrift
    $byCompany = [];
    foreach ($sessions as $session) {
        $companyId = $session['company_id'];
        if (!isset($byCompany[$companyId])) {
            $byCompany[$companyId] = [
                'company_id' => $companyId,
                'company_name' => $session['company_name'],
                'subdomain' => $session['subdomain'],
                'active_users' => 0,
                'users' => []
            ];
        }
        
        $byCompany[$companyId]['active_users']++;
        $byCompany[$companyId]['users'][] = [
            'username' => $session['username'],
            'name' => $session['admin_name'],
            'last_activity' => $session['last_activity'],
            'minutes_idle' => (int)$session['minutes_idle'],
            'ip_address' => $session['ip_address']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'total_active_sessions' => count($sessions),
        'total_companies_active' => count($byCompany),
        'sessions' => $sessions,
        'by_company' => array_values($byCompany),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Get active sessions error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved henting av aktive sessions'
    ]);
}
