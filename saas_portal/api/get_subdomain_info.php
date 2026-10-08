<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
require_once __DIR__ . '/../includes/DomeneshopService.php';

header('Content-Type: application/json');

try {
    $companyId = $_GET['company_id'] ?? '';
    
    if (empty($companyId)) {
        echo json_encode(['success' => false, 'message' => 'Mangler company_id']);
        exit;
    }
    
    $db = getDBConnection();
    
    $stmt = $db->prepare("
        SELECT id, name, subdomain, subdomain_active, subdomain_dns_created, 
               subdomain_last_check, subdomain_status 
        FROM tenant_companies 
        WHERE id = ?
    ");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        echo json_encode(['success' => false, 'message' => 'Firma ikke funnet']);
        exit;
    }
    
    $dnsService = new DomeneshopService($db);
    
    $domainId = $dnsService->getDomainId('salesman.no');
    
    if (!$domainId) {
        echo json_encode([
            'success' => false, 
            'message' => 'Kunne ikke finne salesman.no domene i Domeneshop'
        ]);
        exit;
    }
    
    $existingRecord = $dnsService->subdomainExists($domainId, $company['subdomain']);
    
    if ($existingRecord) {
        $dnsService->updateSubdomainStatus($companyId, 'active', false);
        
        echo json_encode([
            'success' => true,
            'subdomain_exists' => true,
            'subdomain' => $company['subdomain'],
            'record_id' => $existingRecord['id'],
            'ip_address' => $existingRecord['data'],
            'ttl' => $existingRecord['ttl'],
            'dns_created' => $company['subdomain_dns_created'],
            'last_check' => date('Y-m-d H:i:s'),
            'status' => 'active'
        ]);
    } else {
        $dnsService->updateSubdomainStatus($companyId, 'pending', false);
        
        echo json_encode([
            'success' => true,
            'subdomain_exists' => false,
            'subdomain' => $company['subdomain'],
            'status' => 'pending'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Get subdomain info error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved henting av DNS-informasjon: ' . $e->getMessage()
    ]);
}
