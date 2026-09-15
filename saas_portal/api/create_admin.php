<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/ActivityLogger.php';

header('Content-Type: application/json');

try {
    $company_id = $_POST['company_id'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $staff_id = trim($_POST['tenant_staff_id'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    
    if (empty($company_id) || empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Påkrevde felt mangler']);
        exit;
    }
    
    $db = getDBConnection();

    if ($staff_id !== '') {
        $stmt = $db->prepare("
            SELECT id, email
            FROM tenant_staff
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$staff_id, $company_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            echo json_encode(['success' => false, 'message' => 'Valgt ansatt tilhører ikke dette firmaet']);
            exit;
        }

        $email = strtolower(trim($staff['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Valgt ansatt må ha en gyldig e-postadresse før konto kan opprettes']);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM tenant_admins WHERE tenant_staff_id = ? LIMIT 1");
        $stmt->execute([$staff_id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Denne ansatte har allerede en innloggingskonto']);
            exit;
        }
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Gyldig e-postadresse er påkrevd']);
        exit;
    }
    
    $stmt = $db->prepare("SELECT id FROM tenant_admins WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Brukernavn er allerede i bruk']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM tenant_admins WHERE company_id = ? AND LOWER(email) = ?");
    $stmt->execute([$company_id, $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'E-postadressen er allerede knyttet til en konto i dette firmaet']);
        exit;
    }
    
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $admin_id = bin2hex(random_bytes(18));
    $saas_admin_id = $_SESSION['saas_admin_id'];
    
    $stmt = $db->prepare("
        INSERT INTO tenant_admins (id, company_id, email, username, password_hash, tenant_staff_id, is_active, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, " . dbBool(true) . ", ?, NOW())
    ");
    $stmt->execute([$admin_id, $company_id, $email, $username, $password_hash, $staff_id ?: null, $saas_admin_id]);
    
    // Logg aktivitet
    try {
        $companyStmt = $db->prepare("SELECT name FROM tenant_companies WHERE id = ?");
        $companyStmt->execute([$company_id]);
        $companyName = $companyStmt->fetchColumn();
        
        if ($companyName) {
            $activityLogger = new ActivityLogger($db);
            $activityLogger->log(
                $company_id,
                $_SESSION['saas_admin_id'],
                "System",
                'admin_created',
                "opprettet ny administrator: {$username}",
                null,
                null,
                40
            );
        }
    } catch (Exception $logError) {
        error_log("Activity logging error: " . $logError->getMessage());
    }
    
    echo json_encode(['success' => true, 'admin_id' => $admin_id]);
    
} catch (PDOException $e) {
    error_log("Create admin error: " . $e->getMessage());
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'message' => 'Kontoen kunne ikke opprettes fordi brukernavn, e-post eller ansattkobling allerede er i bruk']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Databasefeil ved opprettelse av konto']);
    }
} catch (Exception $e) {
    error_log("Create admin error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Feil ved opprettelse av konto']);
}
