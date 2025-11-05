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
    
    // Valider at selskapet eksisterer
    $stmt = $db->prepare("SELECT id FROM tenant_companies WHERE id = ?");
    $stmt->execute([$company_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Firma ikke funnet']);
        exit;
    }
    
    $db->beginTransaction();
    
    // Bygg UPDATE-spørring
    $fields = [];
    $params = [];
    
    // Obligatoriske felt
    if (isset($data['name']) && !empty($data['name'])) {
        $fields[] = "name = ?";
        $params[] = $data['name'];
    } else {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Firmanavn er påkrevd']);
        exit;
    }
    
    // Valgfrie felt
    $optionalFields = [
        'legal_name', 'org_number', 'industry', 'about_us',
        'contact_email', 'contact_phone', 'website', 'logo_url',
        'address_street', 'address_zip', 'address_city', 'address_country'
    ];
    
    foreach ($optionalFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $params[] = $data[$field];
        }
    }
    
    // Håndter passord hvis det er oppgitt
    if (isset($data['admin_password']) && !empty($data['admin_password'])) {
        if (strlen($data['admin_password']) < 6) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Passordet må være minst 6 tegn']);
            exit;
        }
        
        $fields[] = "admin_password_hash = ?";
        $params[] = password_hash($data['admin_password'], PASSWORD_DEFAULT);
        
        $fields[] = "password_last_changed = NOW()";
    }
    
    // Legg til company_id som siste parameter
    $params[] = $company_id;
    
    // Utfør oppdatering
    $sql = "UPDATE tenant_companies SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Firmadata oppdatert',
        'updated_fields' => count($fields)
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Update company data error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database-feil: ' . $e->getMessage()]);
}
