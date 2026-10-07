<?php
require_once __DIR__ . '/portal_request.php';
$requestData = portalPostData();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/ActivityLogger.php';

header('Content-Type: application/json');

try {
    $data = $requestData;
    $admin_id = $data['admin_id'] ?? '';
    $module_ids = $data['module_ids'] ?? [];
    
    if (!is_string($admin_id) || $admin_id === '' || strlen($admin_id) > 36) {
        echo json_encode(['success' => false, 'message' => 'Admin ID mangler']);
        exit;
    }
    
    if (!is_array($module_ids) || !array_is_list($module_ids) || count($module_ids) > 200 ||
        array_filter($module_ids, static fn($id) => !is_string($id) || $id === '' || strlen($id) > 36)) {
        echo json_encode(['success' => false, 'message' => 'Ugyldig modul-data']);
        exit;
    }
    
    $db = getDBConnection();
    $module_ids = array_values(array_unique($module_ids));
    $columns = $db->query('SHOW COLUMNS FROM tenant_admin_modules')->fetchAll(PDO::FETCH_COLUMN);
    $grantor = $_SESSION['saas_admin_id'];
    if (in_array('granted_by', $columns, true)) {
        $fk = $db->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_admin_modules' AND COLUMN_NAME = 'granted_by' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn();
        // A SaaS operator is not a tenant login. Preserve FK integrity; the
        // SaaS actor remains recorded in the activity log after commit.
        if ($fk && $fk !== 'saas_admins') $grantor = null;
    }
    $db->beginTransaction();
    
    // Valider at admin tilhører et firma
    $stmt = $db->prepare("SELECT company_id FROM tenant_admins WHERE id = ? FOR UPDATE");
    $stmt->execute([$admin_id]);
    $company_id = $stmt->fetchColumn();
    
    if (!$company_id) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Admin ikke funnet']);
        exit;
    }
    
    // Valider at alle modul-IDer tilhører firmaet
    if (!empty($module_ids)) {
        $placeholders = str_repeat('?,', count($module_ids) - 1) . '?';
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM tenant_company_modules 
            WHERE company_id = ? AND module_id IN ($placeholders) AND is_active = " . dbBool(true) . "
              AND module_id IN (SELECT id FROM system_modules WHERE is_active = " . dbBool(true) . ")"
        );
        $stmt->execute(array_merge([$company_id], $module_ids));
        $validCount = $stmt->fetchColumn();
        
        if ($validCount != count($module_ids)) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'En eller flere moduler tilhører ikke firmaet']);
            exit;
        }
    }
    
    // Slett eksisterende moduler
    $stmt = $db->prepare("DELETE FROM tenant_admin_modules WHERE tenant_admin_id = ?");
    $stmt->execute([$admin_id]);
    
    // Sett inn nye moduler
    foreach ($module_ids as $module_id) {
        $values = ['tenant_admin_id' => $admin_id, 'module_id' => $module_id];
        if (in_array('id', $columns, true)) $values['id'] = bin2hex(random_bytes(18));
        if (in_array('granted_by', $columns, true)) $values['granted_by'] = $grantor;
        if (in_array('granted_at', $columns, true)) $values['granted_at'] = date('Y-m-d H:i:s');
        $names = '`' . implode('`, `', array_keys($values)) . '`';
        $marks = implode(',', array_fill(0, count($values), '?'));
        $insertStmt = $db->prepare("INSERT INTO tenant_admin_modules ({$names}) VALUES ({$marks})");
        $insertStmt->execute(array_values($values));
    }
    
    $db->commit();
    
    // Logg aktivitet
    try {
        $companyStmt = $db->prepare("
            SELECT tc.name, ta.username
            FROM tenant_companies tc
            INNER JOIN tenant_admins ta ON tc.id = ta.company_id
            WHERE ta.id = ?
        ");
        $companyStmt->execute([$admin_id]);
        $data = $companyStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $activityLogger = new ActivityLogger($db);
            $activityLogger->log(
                $company_id,
                $_SESSION['saas_admin_id'],
                "{$data['name']}: System",
                'modules_changed',
                "oppdaterte modultilgang for admin {$data['username']} (" . count($module_ids) . " moduler)",
                null,
                null,
                35
            );
        }
    } catch (Exception $logError) {
        error_log("Activity logging error: " . $logError->getMessage());
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Update admin modules error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Modultilgangene kunne ikke lagres. Ingen endringer er gjort.']);
}
