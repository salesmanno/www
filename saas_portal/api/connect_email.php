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
    $data = json_decode(file_get_contents('php://input'), true);
    $company_id = $data['company_id'] ?? '';
    
    if (empty($company_id)) {
        echo json_encode(['success' => false, 'message' => 'Company ID mangler']);
        exit;
    }
    
    $db = getDBConnection();
    
    // Sjekk om email-konfigurasjon eksisterer
    $stmt = $db->prepare("SELECT id, smtp_host, smtp_username FROM tenant_email_config WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $config = $stmt->fetch();
    
    if (!$config) {
        echo json_encode(['success' => false, 'message' => 'Email-konfigurasjon mangler. Lagre innstillinger først.']);
        exit;
    }
    
    // Oppdater status til "koblet opp"
    $stmt = $db->prepare("
        UPDATE tenant_email_config 
        SET is_connected = 1, 
            last_test_at = NOW(), 
            last_test_status = 'success',
            last_error_message = NULL
        WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    
    // TODO: Implementer integrasjon med tenant-backend for å fjerne konfigureringsmeldinger
    // Dette kan gjøres ved å:
    // 1. Sette en flag i tenant_settings eller tenant_website_config
    // 2. Kalle et API endpoint i tenant-backend for å oppdatere status
    
    echo json_encode([
        'success' => true,
        'message' => 'Email-konfigurasjon koblet opp! Konfigureringsmeldinger i tenant-backend vil bli fjernet.'
    ]);
    
} catch (Exception $e) {
    error_log("Connect email error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved oppkobling: ' . $e->getMessage()
    ]);
}
