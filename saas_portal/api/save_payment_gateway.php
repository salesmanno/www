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
    $gateway_name = $data['gateway_name'] ?? '';
    
    if (empty($company_id) || empty($gateway_name)) {
        echo json_encode(['success' => false, 'message' => 'Company ID eller gateway name mangler']);
        exit;
    }
    
    $db = getDBConnection();
    
    // Sjekk om gateway allerede eksisterer
    $stmt = $db->prepare("SELECT id FROM economy_payment_gateways WHERE company_id = ? AND gateway_name = ?");
    $stmt->execute([$company_id, $gateway_name]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        // Oppdater eksisterende gateway
        $sql = "UPDATE economy_payment_gateways SET 
                display_name = ?,
                is_active = ?,
                test_mode = ?,
                updated_at = NOW()";
        
        $params = [
            $data['display_name'] ?? $gateway_name,
            isset($data['is_active']) && $data['is_active'] === 'on' ? 1 : 0,
            isset($data['test_mode']) && $data['test_mode'] === 'on' ? 1 : 0
        ];
        
        // Legg til gateway-spesifikke felter
        if ($gateway_name === 'vipps') {
            $sql .= ", vipps_client_id = ?, vipps_merchant_serial_number = ?, vipps_subscription_key = ?";
            $params[] = $data['vipps_client_id'] ?? '';
            $params[] = $data['vipps_merchant_serial_number'] ?? '';
            $params[] = $data['vipps_subscription_key'] ?? '';
            
            if (!empty($data['vipps_client_secret'])) {
                $sql .= ", vipps_client_secret_encrypted = ?";
                $params[] = $data['vipps_client_secret'];
            }
        } elseif ($gateway_name === 'stripe') {
            $sql .= ", stripe_public_key = ?, stripe_webhook_secret = ?";
            $params[] = $data['stripe_public_key'] ?? '';
            $params[] = $data['stripe_webhook_secret'] ?? '';
            
            if (!empty($data['stripe_secret_key'])) {
                $sql .= ", stripe_secret_key_encrypted = ?";
                $params[] = $data['stripe_secret_key'];
            }
        } elseif ($gateway_name === 'paypal') {
            $sql .= ", paypal_client_id = ?, paypal_webhook_id = ?";
            $params[] = $data['paypal_client_id'] ?? '';
            $params[] = $data['paypal_webhook_id'] ?? '';
            
            if (!empty($data['paypal_client_secret'])) {
                $sql .= ", paypal_client_secret_encrypted = ?";
                $params[] = $data['paypal_client_secret'];
            }
        }
        
        $sql .= " WHERE company_id = ? AND gateway_name = ?";
        $params[] = $company_id;
        $params[] = $gateway_name;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    } else {
        // Opprett ny gateway
        $sql = "INSERT INTO economy_payment_gateways (
                company_id, gateway_name, display_name, is_active, test_mode, created_at, updated_at";
        $values = "?, ?, ?, ?, ?, NOW(), NOW()";
        
        $params = [
            $company_id,
            $gateway_name,
            $data['display_name'] ?? $gateway_name,
            isset($data['is_active']) && $data['is_active'] === 'on' ? 1 : 0,
            isset($data['test_mode']) && $data['test_mode'] === 'on' ? 1 : 0
        ];
        
        // Legg til gateway-spesifikke felter
        if ($gateway_name === 'vipps') {
            $sql .= ", vipps_client_id, vipps_client_secret_encrypted, vipps_merchant_serial_number, vipps_subscription_key";
            $values .= ", ?, ?, ?, ?";
            $params[] = $data['vipps_client_id'] ?? '';
            $params[] = $data['vipps_client_secret'] ?? '';
            $params[] = $data['vipps_merchant_serial_number'] ?? '';
            $params[] = $data['vipps_subscription_key'] ?? '';
        } elseif ($gateway_name === 'stripe') {
            $sql .= ", stripe_public_key, stripe_secret_key_encrypted, stripe_webhook_secret";
            $values .= ", ?, ?, ?";
            $params[] = $data['stripe_public_key'] ?? '';
            $params[] = $data['stripe_secret_key'] ?? '';
            $params[] = $data['stripe_webhook_secret'] ?? '';
        } elseif ($gateway_name === 'paypal') {
            $sql .= ", paypal_client_id, paypal_client_secret_encrypted, paypal_webhook_id";
            $values .= ", ?, ?, ?";
            $params[] = $data['paypal_client_id'] ?? '';
            $params[] = $data['paypal_client_secret'] ?? '';
            $params[] = $data['paypal_webhook_id'] ?? '';
        }
        
        $sql .= ") VALUES ($values)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Betalingsgateway lagret'
    ]);
    
} catch (Exception $e) {
    error_log("Save payment gateway error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved lagring: ' . $e->getMessage()
    ]);
}
