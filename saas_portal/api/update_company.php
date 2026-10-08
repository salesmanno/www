<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';

header('Content-Type: application/json');

try {
    $company_id = $_POST['company_id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $is_active = intval($_POST['is_active'] ?? 1);
    
    if (empty($company_id) || empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Company ID og navn er påkrevd']);
        exit;
    }
    
    $db = getDBConnection();
    
    $stmt = $db->prepare("
        UPDATE tenant_companies 
        SET name = ?, contact_email = ?, contact_phone = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$name, $email, $phone, $is_active, $company_id]);
    
    echo json_encode(['success' => true, 'message' => 'Bedrift oppdatert']);
    
} catch (Exception $e) {
    error_log("Update company error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Feil: ' . $e->getMessage()]);
}
