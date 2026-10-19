<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
require_once __DIR__ . '/../services/ActivityLogger.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $staff_id = $data['staff_id'] ?? '';
    
    $db = getDBConnection();
    
    // Hent info før sletting
    $stmt = $db->prepare("
        SELECT ts.first_name, ts.last_name, tc.id as company_id, tc.name as company_name
        FROM tenant_staff ts
        INNER JOIN tenant_companies tc ON ts.company_id = tc.id
        WHERE ts.id = ?
    ");
    $stmt->execute([$staff_id]);
    $staffInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("DELETE FROM tenant_staff WHERE id = ?");
    $stmt->execute([$staff_id]);
    
    // Logg aktivitet
    if ($staffInfo) {
        try {
            $activityLogger = new ActivityLogger($db);
            $activityLogger->log(
                $staffInfo['company_id'],
                $_SESSION['saas_admin_id'],
                "{$staffInfo['company_name']}: System",
                'staff_deleted',
                "slettet ansatt: {$staffInfo['first_name']} {$staffInfo['last_name']}",
                null,
                null,
                35
            );
        } catch (Exception $logError) {
            error_log("Activity logging error: " . $logError->getMessage());
        }
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("Delete staff error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
