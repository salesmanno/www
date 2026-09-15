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
            ts.id,
            ts.first_name,
            ts.last_name,
            ts.email,
            ts.phone,
            ts.job_title,
            EXISTS(
                SELECT 1
                FROM tenant_admins ta
                WHERE ta.tenant_staff_id = ts.id
            ) AS has_account
        FROM tenant_staff ts
        WHERE ts.company_id = ?
        ORDER BY first_name, last_name
    ");
    $stmt->execute([$company_id]);
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'staff' => $staff]);
    
} catch (Exception $e) {
    error_log("Get staff error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
