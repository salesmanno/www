<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
require_once __DIR__ . '/../services/ActivityLogger.php';

header('Content-Type: application/json');

try {
    $company_id = $_POST['company_id'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $job_title = trim($_POST['job_title'] ?? '');
    
    if (empty($company_id) || empty($first_name) || empty($last_name)) {
        echo json_encode(['success' => false, 'message' => 'Fornavn og etternavn er påkrevd']);
        exit;
    }
    
    $db = getDBConnection();
    
    $staff_id = bin2hex(random_bytes(18));
    
    $stmt = $db->prepare("
        INSERT INTO tenant_staff (id, company_id, first_name, last_name, email, phone, job_title, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$staff_id, $company_id, $first_name, $last_name, $email, $phone, $job_title]);
    
    // Logg aktivitet
    try {
        $companyStmt = $db->prepare("SELECT name FROM tenant_companies WHERE id = ?");
        $companyStmt->execute([$company_id]);
        $companyName = $companyStmt->fetchColumn();
        
        if ($companyName) {
            $activityLogger = new ActivityLogger($db);
            $activityLogger->log(
                $company_id,
                $_SESSION['saas_admin_id'],
                "{$companyName}: System",
                'staff_created',
                "opprettet ny ansatt: {$first_name} {$last_name}",
                null,
                null,
                30
            );
        }
    } catch (Exception $logError) {
        error_log("Activity logging error: " . $logError->getMessage());
    }
    
    echo json_encode(['success' => true, 'staff_id' => $staff_id]);
    
} catch (Exception $e) {
    error_log("Create staff error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
