<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';

try {
    $db = getDBConnection();
    
    // Check if payment_providers table exists (graceful degradation for MySQL production)
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'payment_providers'");
        $tableExists = $stmt->fetch() !== false;
    } catch (Exception $e) {
        // Try PostgreSQL syntax
        try {
            $stmt = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'payment_providers')");
            $tableExists = $stmt->fetchColumn();
        } catch (Exception $e2) {
            $tableExists = false;
        }
    }
    
    if ($tableExists) {
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
    } else {
        // Table doesn't exist - return empty array (no payment methods configured)
        echo json_encode([
            'success' => true,
            'payment_methods' => []
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error fetching payment methods: " . $e->getMessage());
    echo json_encode([
        'success' => true,
        'payment_methods' => []
    ]);
}
