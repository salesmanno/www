<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
require_once __DIR__ . '/../services/PortalDatabase.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $db = PortalDatabase::connect();
    $companyId = $_GET['company_id'] ?? null;
    if ($companyId !== null && (!is_string($companyId) || $companyId === '' || strlen($companyId) > 36)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ugyldig bedrift.']);
        exit;
    }
    
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
          " . ($companyId !== null ? 'AND ta.company_id = ?' : '') . "
        ORDER BY tas.last_activity DESC
    ");
    
    $stmt->execute($companyId !== null ? [$companyId] : []);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sessions as &$session) $session['identity_kind']='tenant';
    unset($session);
    if ($companyId===null && $db->query("SHOW TABLES LIKE 'saas_global_sessions'")->fetchColumn()) {
        $global=$db->query("SELECT gs.id AS session_id,gs.saas_admin_id AS admin_id,gs.login_at,gs.last_activity,
            NULL AS company_id,'Global SaaS-bruker' AS company_name,NULL AS subdomain,
            sa.email AS username,sa.email AS admin_name,NULL AS ip_address,
            TIMESTAMPDIFF(MINUTE,gs.last_activity,NOW()) AS minutes_idle,'saas_global' AS identity_kind
            FROM saas_global_sessions gs JOIN saas_admins sa ON sa.id=gs.saas_admin_id
            WHERE gs.revoked_at IS NULL AND gs.last_activity>DATE_SUB(NOW(),INTERVAL 30 MINUTE)
            AND sa.is_active=1 AND sa.role='global_admin' ORDER BY gs.last_activity DESC")->fetchAll(PDO::FETCH_ASSOC);
        $sessions=array_merge($sessions,$global);
    }
    
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
            'session_id' => $session['session_id'],
            'admin_id' => $session['tenant_admin_id'] ?? $session['admin_id'],
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
    
} catch (Throwable $e) {
    [$status, $body] = PortalDatabase::failure($e, 'active_sessions', 'Kunne ikke hente aktive innlogginger. Prøv igjen.');
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
}
