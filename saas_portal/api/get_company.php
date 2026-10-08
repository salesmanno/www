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
    $company_id = $_GET['id'] ?? '';
    
    if (empty($company_id)) {
        echo json_encode(['success' => false, 'message' => 'Company ID mangler']);
        exit;
    }
    
    $db = getDBConnection();
    
    $stmt = $db->prepare("SELECT * FROM tenant_companies WHERE id = ?");
    $stmt->execute([$company_id]);
    $company = $stmt->fetch();
    
    if (!$company) {
        echo json_encode(['success' => false, 'message' => 'Bedrift ikke funnet']);
        exit;
    }
    
    echo json_encode(['success' => true, 'company' => $company]);
    
} catch (Exception $e) {
    error_log("Get company error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Feil: ' . $e->getMessage()]);
}
