<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
require_once __DIR__ . '/../services/PortalSuperUserQuota.php';

header('Content-Type: application/json');

$company_id = $_GET['company_id'] ?? '';

try {
    $db = getDBConnection();
    $linksInstalled=(bool)$db->query("SHOW TABLES LIKE 'saas_global_account_links'")->fetchColumn();
    $excludeGlobal=$linksInstalled ? 'AND NOT EXISTS (SELECT 1 FROM saas_global_account_links gl WHERE gl.source_tenant_admin_id=ta.id)' : '';
    
    $stmt = $db->prepare("
        SELECT 
            ta.id as admin_id,
            ta.tenant_staff_id,
            ta.email AS account_email,
            ta.username,
            ta.is_active,
            ta.last_login,
            ta.created_at,
            ts.first_name,
            ts.last_name,
            ts.email,
            ts.phone,
            ts.job_title
        FROM tenant_admins ta
        LEFT JOIN tenant_staff ts ON ta.tenant_staff_id = ts.id
        WHERE ta.company_id = ?
        {$excludeGlobal}
        ORDER BY ta.username
    ");
    $stmt->execute([$company_id]);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $limitQuery = $db->prepare('SELECT p.max_users FROM tenant_subscriptions s LEFT JOIN saas_subscription_plans p ON p.id=s.plan_id WHERE s.company_id=?');
    $limitQuery->execute([$company_id]);
    $limits = $limitQuery->fetchAll(PDO::FETCH_COLUMN);
    $limit = count($limits) === 1 ? PortalSuperUserQuota::limit($limits[0]) : null;
    echo json_encode(['success' => true, 'admins' => $admins, 'quota'=>[
        'max_users'=>$limit,'active_users'=>PortalSuperUserQuota::activeCount($db,$company_id)
    ]]);
    
} catch (Exception $e) {
    error_log('Get admins failed.');
    echo json_encode(['success' => false, 'message' => 'Kunne ikke hente brukere og brukergrense.']);
}
