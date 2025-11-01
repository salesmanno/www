<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';

try {
    $db = getDBConnection();
    
    // Get active payment providers
    $stmt = $db->prepare("
        SELECT provider_id, provider_name, display_name 
        FROM payment_providers 
        WHERE is_active = true
        ORDER BY provider_id
    ");
    $stmt->execute();
    $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'payment_methods' => $methods
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching payment methods: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Kunne ikke hente betalingsmetoder',
        'payment_methods' => []
    ]);
}
