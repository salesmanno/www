<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/DomeneshopService.php';

header('Content-Type: application/json');

try {
    $name = trim($_POST['name'] ?? '');
    $subdomain = trim($_POST['subdomain'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $org_number = trim($_POST['org_number'] ?? '');
    $legal_name = trim($_POST['legal_name'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $address_street = trim($_POST['address_street'] ?? '');
    $address_zip = trim($_POST['address_zip'] ?? '');
    $address_city = trim($_POST['address_city'] ?? '');
    
    if (empty($name) || empty($subdomain)) {
        echo json_encode(['success' => false, 'message' => 'Navn og subdomain er påkrevd']);
        exit;
    }
    
    if (!preg_match('/^[a-z0-9]+$/', $subdomain)) {
        echo json_encode(['success' => false, 'message' => 'Subdomain kan kun inneholde lowercase bokstaver og tall']);
        exit;
    }
    
    $db = getDBConnection();
    $db->beginTransaction();
    
    $checkStmt = $db->prepare("SELECT id FROM tenant_companies WHERE subdomain = ?");
    $checkStmt->execute([$subdomain]);
    if ($checkStmt->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Subdomain er allerede i bruk']);
        exit;
    }
    
    $company_id = bin2hex(random_bytes(18));
    $saas_admin_id = $_SESSION['saas_admin_id'];
    
    $stmt = $db->prepare("
        INSERT INTO tenant_companies (
            id, name, subdomain, contact_email, contact_phone, 
            org_number, legal_name, industry, 
            address_street, address_zip, address_city, 
            onboarded_by, is_active, created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . dbBool(true) . ", NOW(), NOW())
    ");
    $stmt->execute([
        $company_id, $name, $subdomain, $email, $phone, 
        $org_number, $legal_name, $industry, 
        $address_street, $address_zip, $address_city,
        $saas_admin_id
    ]);
    
    $modulesStmt = $db->query("SELECT id FROM system_modules WHERE is_active = " . dbBool(true));
    $modules = $modulesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $insertModuleStmt = $db->prepare("
        INSERT INTO tenant_company_modules (company_id, module_id, is_active, activated_at, assigned_by)
        VALUES (?, ?, " . dbBool(true) . ", NOW(), ?)
    ");
    
    foreach ($modules as $module_id) {
        $insertModuleStmt->execute([$company_id, $module_id, $saas_admin_id]);
    }
    
    $stmt = $db->prepare("
        INSERT INTO tenant_customers (id, company_id, created_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([bin2hex(random_bytes(18)), $company_id]);
    
    $stmt = $db->prepare("
        INSERT INTO tenant_settings (id, company_id, created_by, created_at)
        VALUES (?, ?, NULL, NOW())
    ");
    $stmt->execute([bin2hex(random_bytes(18)), $company_id]);
    
    $stmt = $db->prepare("
        INSERT INTO tenant_website_general (id, company_id, created_by, created_at)
        VALUES (?, ?, NULL, NOW())
    ");
    $stmt->execute([bin2hex(random_bytes(18)), $company_id]);
    
    $stmt = $db->prepare("
        INSERT INTO tenant_subscriptions (id, company_id, created_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([bin2hex(random_bytes(18)), $company_id]);
    
    // Opprett subdomain i Domeneshop
    $dnsResult = null;
    try {
        // Hent Domeneshop domain ID fra konfigurasjon
        $domainStmt = $db->prepare("SELECT config_value FROM saas_system_config WHERE config_key = 'domeneshop_domain_id'");
        $domainStmt->execute();
        $domainId = $domainStmt->fetchColumn();
        
        if ($domainId) {
            $domeneshop = new DomeneshopService($db);
            $dnsResult = $domeneshop->createSubdomain($domainId, $subdomain);
        } else {
            $dnsResult = ['success' => false, 'message' => 'Domeneshop domain ID ikke konfigurert'];
        }
    } catch (Exception $dnsError) {
        // Log feil men fortsett - DNS er ikke kritisk for bedriftsopprettelse
        error_log("Domeneshop DNS error: " . $dnsError->getMessage());
        $dnsResult = ['success' => false, 'message' => $dnsError->getMessage()];
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Bedrift opprettet',
        'company_id' => $company_id,
        'modules_granted' => count($modules),
        'dns' => $dnsResult
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Create company error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database feil: ' . $e->getMessage()]);
}
