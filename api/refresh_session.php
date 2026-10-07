<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/TenantSessionGuard.php';

header('Content-Type: application/json');

if (!isset($_SESSION['tenant_admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

try {
    $db = getDBConnection();
    if (!$db) {
        echo json_encode(['success' => false, 'error' => 'Database unavailable']);
        exit;
    }
    
    if (!tenantSessionIsActive($db, $_SESSION['session_id'] ?? session_id(), (string)$_SESSION['tenant_admin_id'])) {
        clearTenantSessionData();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesjonen er avsluttet.', 'session_ended' => true]);
        exit;
    }
    updateSessionActivity($_SESSION['session_id'] ?? session_id());
    $stmt = $db->prepare("
        SELECT ta.id, ta.company_id, ta.email,
               tc.name as company_name, tc.subdomain,
               ts.first_name, ts.last_name, ts.email as staff_email
        FROM tenant_admins ta
        INNER JOIN tenant_companies tc ON ta.company_id = tc.id
        LEFT JOIN tenant_staff ts ON ta.tenant_staff_id = ts.id
        WHERE ta.id = ?
    ");
    $stmt->execute([$_SESSION['tenant_admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        $_SESSION['admin_email'] = $admin['staff_email'] ?? $admin['email'];
        $_SESSION['admin_first_name'] = $admin['first_name'] ?? '';
        $_SESSION['admin_last_name'] = $admin['last_name'] ?? '';
        $_SESSION['admin_name'] = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
        $_SESSION['company_name'] = $admin['company_name'];
        $_SESSION['subdomain'] = $admin['subdomain'];
        
        echo json_encode([
            'success' => true, 
            'admin_name' => $_SESSION['admin_name'],
            'company_name' => $_SESSION['company_name']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Admin not found']);
    }
    
} catch (Exception $e) {
    error_log("Refresh session error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'System error']);
}
