<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

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
    
    echo json_encode(['success' => true, 'staff_id' => $staff_id]);
    
} catch (Exception $e) {
    error_log("Create staff error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
