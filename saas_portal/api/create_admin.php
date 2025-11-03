<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    $company_id = $_POST['company_id'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $staff_id = $_POST['tenant_staff_id'] ?? null;
    
    if (empty($company_id) || empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Påkrevde felt mangler']);
        exit;
    }
    
    $db = getDBConnection();
    
    $stmt = $db->prepare("SELECT id FROM tenant_admins WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Brukernavn er allerede i bruk']);
        exit;
    }
    
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $admin_id = bin2hex(random_bytes(18));
    
    $stmt = $db->prepare("
        INSERT INTO tenant_admins (id, company_id, username, password_hash, tenant_staff_id, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, " . dbBool(true) . ", NOW())
    ");
    $stmt->execute([$admin_id, $company_id, $username, $password_hash, $staff_id ?: null]);
    
    echo json_encode(['success' => true, 'admin_id' => $admin_id]);
    
} catch (Exception $e) {
    error_log("Create admin error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
