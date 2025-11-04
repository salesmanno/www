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
    
    // Sjekk om konfigurasjon allerede eksisterer
    $stmt = $db->prepare("SELECT id FROM tenant_email_config WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        // Oppdater eksisterende konfigurasjon
        // VIKTIG: Når credentials endres, må tilkoblingsstatus resettes
        $sql = "UPDATE tenant_email_config SET 
                smtp_host = ?,
                smtp_port = ?,
                smtp_encryption = ?,
                smtp_username = ?,
                smtp_from_email = ?,
                smtp_from_name = ?,
                imap_host = ?,
                imap_port = ?,
                imap_encryption = ?,
                imap_username = ?,
                is_connected = 0,
                last_test_status = 'not_tested',
                last_test_at = NULL,
                last_error_message = NULL,
                updated_at = NOW()";
        
        $params = [
            $data['smtp_host'] ?? '',
            $data['smtp_port'] ?? 587,
            $data['smtp_encryption'] ?? 'tls',
            $data['smtp_username'] ?? '',
            $data['smtp_from_email'] ?? '',
            $data['smtp_from_name'] ?? '',
            $data['imap_host'] ?? '',
            $data['imap_port'] ?? 993,
            $data['imap_encryption'] ?? 'ssl',
            $data['imap_username'] ?? ''
        ];
        
        // Oppdater passord kun hvis det er oppgitt
        if (!empty($data['smtp_password'])) {
            $sql .= ", smtp_password = ?";
            $params[] = $data['smtp_password'];
        }
        if (!empty($data['imap_password'])) {
            $sql .= ", imap_password = ?";
            $params[] = $data['imap_password'];
        }
        
        $sql .= " WHERE company_id = ?";
        $params[] = $company_id;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    } else {
        // Opprett ny konfigurasjon
        $stmt = $db->prepare("
            INSERT INTO tenant_email_config (
                company_id, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password,
                smtp_from_email, smtp_from_name,
                imap_host, imap_port, imap_encryption, imap_username, imap_password,
                is_connected, last_test_status,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'not_tested', NOW(), NOW())
        ");
        
        $stmt->execute([
            $company_id,
            $data['smtp_host'] ?? '',
            $data['smtp_port'] ?? 587,
            $data['smtp_encryption'] ?? 'tls',
            $data['smtp_username'] ?? '',
            $data['smtp_password'] ?? '',
            $data['smtp_from_email'] ?? '',
            $data['smtp_from_name'] ?? '',
            $data['imap_host'] ?? '',
            $data['imap_port'] ?? 993,
            $data['imap_encryption'] ?? 'ssl',
            $data['imap_username'] ?? '',
            $data['imap_password'] ?? ''
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Epost-konfigurasjon lagret'
    ]);
    
} catch (Exception $e) {
    error_log("Save email config error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved lagring: ' . $e->getMessage()
    ]);
}
