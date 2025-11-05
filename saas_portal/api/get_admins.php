<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$company_id = $_GET['company_id'] ?? '';

try {
    $db = getDBConnection();
    
    $stmt = $db->prepare("
        SELECT 
            ta.id as admin_id,
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
        ORDER BY ta.username
    ");
    $stmt->execute([$company_id]);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'admins' => $admins]);
    
} catch (Exception $e) {
    error_log("Get admins error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
