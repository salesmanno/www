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
    
    // Deaktiver foreign key checks midlertidig for å unngå constraint-problemer
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // FASE 1: Slett barn-tabeller med foreign keys først
    $relatedDeletes = [
        // Events check-in må slettes først (har FK til customers og events)
        "DELETE FROM events_checkin WHERE event_id IN (SELECT id FROM events_eventdata WHERE company_id = ?)",
        
        // Sales transactions må slettes først (har FK til products og orders)
        "DELETE FROM sales_transactions WHERE company_id = ?",
        
        // Sales order items må slettes (har FK til orders)
        "DELETE FROM sales_orders_items WHERE order_id IN (SELECT id FROM sales_orders WHERE company_id = ?)",
        
        // Service bookings må slettes (har FK til services)
        "DELETE FROM reservation_service_bookings WHERE service_id IN (SELECT id FROM reservation_services WHERE company_id = ?)",
        
        // Admin/Staff sessions og modules (kan mangle company_id, bruker JOIN)
        "DELETE FROM tenant_admin_sessions WHERE admin_id IN (SELECT id FROM tenant_admins WHERE company_id = ?)",
        "DELETE FROM tenant_admin_modules WHERE admin_id IN (SELECT id FROM tenant_admins WHERE company_id = ?)",
        "DELETE FROM tenant_staff_modules WHERE staff_id IN (SELECT id FROM tenant_staff WHERE company_id = ?)",
    ];
    
    foreach ($relatedDeletes as $sql) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$company_id]);
        } catch (Exception $e) {
            error_log("Warning: " . $e->getMessage());
        }
    }
    
    // FASE 2: Nullstill alle foreign keys som peker til admins/staff FØR sletting
    try {
        // Nullstill created_by i tenant_website_config (hvis kolonnen eksisterer)
        $stmt = $db->prepare("UPDATE tenant_website_config SET created_by = NULL WHERE company_id = ?");
        $stmt->execute([$company_id]);
    } catch (Exception $e) {
        error_log("Warning updating tenant_website_config.created_by: " . $e->getMessage());
    }
    
    try {
        // Nullstill andre foreign keys som peker til admins/staff
        $stmt = $db->prepare("UPDATE tenant_website_general SET updated_by = NULL WHERE company_id = ?");
        $stmt->execute([$company_id]);
    } catch (Exception $e) {
        error_log("Warning updating tenant_website_general.updated_by: " . $e->getMessage());
    }
    
    try {
        // Nullstill assigned_by i tenant_company_modules
        $stmt = $db->prepare("UPDATE tenant_company_modules SET assigned_by = NULL WHERE company_id = ?");
        $stmt->execute([$company_id]);
    } catch (Exception $e) {
        error_log("Warning updating tenant_company_modules.assigned_by: " . $e->getMessage());
    }
    
    // FASE 3: Slett hovedtabellene i riktig rekkefølge
    $mainTables = [
        'tenant_company_modules',
        'tenant_announcements',
        'tenant_website_config',
        'tenant_website_general',
        'tenant_settings',
        'tenant_subscriptions',
        'events_results',
        'events_eventdata',
        'membership_members',
        'membership_plans',
        'reservation_services',
        'sales_orders',
        'sales_products',
        'sales_cart',
        'rental_objects',
        'rental_schedule',
        'service_bookings',
        'unified_ledger',
        'tenant_customers',
        'tenant_admins',
        'tenant_staff'
    ];
    
    foreach ($mainTables as $table) {
        try {
            $stmt = $db->prepare("DELETE FROM $table WHERE company_id = ?");
            $stmt->execute([$company_id]);
        } catch (Exception $e) {
            error_log("Warning deleting from $table: " . $e->getMessage());
        }
    }
    
    $stmt = $db->prepare("DELETE FROM tenant_companies WHERE id = ?");
    $stmt->execute([$company_id]);
    
    // Re-aktiver foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    $db->commit();
    
    echo json_encode(['success' => true, 'message' => 'Bedrift slettet']);
    
} catch (Exception $e) {
    if (isset($db)) {
        // Re-aktiver foreign key checks selv ved feil
        try {
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        } catch (Exception $fkError) {
            error_log("Error re-enabling FK checks: " . $fkError->getMessage());
        }
        
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    error_log("Delete company error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Feil ved sletting: ' . $e->getMessage()]);
}
