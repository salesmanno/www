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
    $input = json_decode(file_get_contents('php://input'), true);
    $companyId = $input['company_id'] ?? '';
    
    if (empty($companyId)) {
        echo json_encode(['success' => false, 'message' => 'Mangler company_id']);
        exit;
    }
    
    $db = getDBConnection();
    
    $stmt = $db->prepare("SELECT id, name, subdomain FROM tenant_companies WHERE id = ?");
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
    
    if (!$existingRecord) {
        echo json_encode([
            'success' => false,
            'message' => 'Subdomain eksisterer ikke'
        ]);
        exit;
    }
    
    $result = $dnsService->deleteDNSRecord($domainId, $existingRecord['id']);
    
    if ($result['success']) {
        $dnsService->updateSubdomainStatus($companyId, 'inactive', false);
        
        $updateStmt = $db->prepare("
            UPDATE tenant_companies 
            SET subdomain_active = 0, subdomain_dns_created = NULL 
            WHERE id = ?
        ");
        $updateStmt->execute([$companyId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Subdomain slettet'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['message']
        ]);
    }
    
} catch (Exception $e) {
    error_log("Delete subdomain error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved sletting av subdomain: ' . $e->getMessage()
    ]);
}
