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
    
    // Hent betalingsgatewayer for bedriften
    $stmt = $db->prepare("
        SELECT gateway_name, display_name, is_active, test_mode,
               vipps_client_id, vipps_merchant_serial_number, vipps_subscription_key,
               paypal_client_id, paypal_webhook_id,
               stripe_public_key, stripe_webhook_secret
        FROM economy_payment_gateways 
        WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    $gateways = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'gateways' => $gateways
    ]);
    
} catch (Exception $e) {
    error_log("Get payment gateways error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved henting av betalingsgatewayer'
    ]);
}
