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
    $company_id = $_GET['company_id'] ?? '';
    
    if (empty($company_id)) {
        echo json_encode(['success' => false, 'message' => 'Company ID mangler']);
        exit;
    }
    
    $db = getDBConnection();
    
    // Hent epost-konfigurasjon for bedriften
    $stmt = $db->prepare("
        SELECT smtp_host, smtp_port, smtp_encryption, smtp_username, 
               smtp_from_email, smtp_from_name,
               imap_host, imap_port, imap_encryption, imap_username,
               updated_at
        FROM tenant_email_config 
        WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($config) {
        echo json_encode([
            'success' => true,
            'config' => $config
        ]);
    } else {
        // Ingen konfigurasjon funnet, returner tom
        echo json_encode([
            'success' => true,
            'config' => null
        ]);
    }
    
} catch (Exception $e) {
    error_log("Get email config error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved henting av epost-konfigurasjon'
    ]);
}
