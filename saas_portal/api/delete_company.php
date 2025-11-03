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
    $db->beginTransaction();
    
    $tables = [
        'tenant_admin_sessions',
        'tenant_admin_modules',
        'tenant_staff_modules',
        'tenant_company_modules',
        'tenant_announcements',
        'tenant_customers',
        'tenant_settings',
        'tenant_website_config',
        'tenant_website_general',
        'tenant_subscriptions',
        'events_eventdata',
        'events_checkin',
        'events_results',
        'membership_members',
        'membership_plans',
        'reservation_services',
        'reservation_service_bookings',
        'sales_products',
        'sales_orders',
        'sales_orders_items',
        'sales_cart',
        'sales_transactions',
        'rental_objects',
        'rental_schedule',
        'service_bookings',
        'unified_ledger',
        'tenant_admins',
        'tenant_staff'
    ];
    
    foreach ($tables as $table) {
        try {
            $stmt = $db->prepare("DELETE FROM $table WHERE company_id = ?");
            $stmt->execute([$company_id]);
        } catch (Exception $e) {
            error_log("Warning deleting from $table: " . $e->getMessage());
        }
    }
    
    $stmt = $db->prepare("DELETE FROM tenant_companies WHERE id = ?");
    $stmt->execute([$company_id]);
    
    $db->commit();
    
    echo json_encode(['success' => true, 'message' => 'Bedrift slettet']);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Delete company error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Feil: ' . $e->getMessage()]);
}
