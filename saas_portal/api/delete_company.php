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
    
    // Kall stored procedure for trygg sletting
    // Prosedyren håndterer transaksjoner og rollback automatisk
    $stmt = $db->prepare("CALL SafeDeleteCompany(?)");
    $stmt->execute([$company_id]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Bedrift slettet med alle tilknyttede data'
    ]);
    
} catch (Exception $e) {
    error_log("Delete company error: " . $e->getMessage());
    
    // Gi mer detaljert feilmelding for debugging
    $errorMessage = 'Feil ved sletting';
    
    // Hvis stored procedure ikke finnes
    if (strpos($e->getMessage(), 'PROCEDURE') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
        $errorMessage = 'Stored procedure ikke installert. Kjør install_procedure.php først.';
    } else {
        $errorMessage .= ': ' . $e->getMessage();
    }
    
    echo json_encode([
        'success' => false, 
        'message' => $errorMessage
    ]);
}
