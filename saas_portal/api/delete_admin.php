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
    $admin_id = $data['admin_id'] ?? '';
    
    $db = getDBConnection();
    
    // Hent info BEFORE sletting for logging
    $stmt = $db->prepare("
        SELECT ta.username, tc.id as company_id, tc.name as company_name
        FROM tenant_admins ta
        INNER JOIN tenant_companies tc ON ta.company_id = tc.id
        WHERE ta.id = ?
    ");
    $stmt->execute([$admin_id]);
    $adminInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("DELETE FROM tenant_admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    
    // Logg aktivitet
    if ($adminInfo) {
        try {
            $activityLogger = new ActivityLogger($db);
            $activityLogger->log(
                $adminInfo['company_id'],
                $_SESSION['saas_admin_id'],
                "System",
                'admin_deleted',
                "slettet administrator: {$adminInfo['username']}",
                null,
                null,
                45
            );
        } catch (Exception $logError) {
            error_log("Activity logging error: " . $logError->getMessage());
        }
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("Delete admin error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
